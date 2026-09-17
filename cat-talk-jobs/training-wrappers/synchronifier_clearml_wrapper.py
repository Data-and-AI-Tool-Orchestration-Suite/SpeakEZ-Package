import argparse
import json
import os
import re
import logging
import traceback
import subprocess
import configparser
import difflib
import requests
import boto3
import botocore  # Added for exception handling in main block
import math

from glob import glob
from os.path import isfile, join
from pathlib import Path

# ClearML and other specific imports
from clearml import Task, Dataset, Logger
from clearml.utilities import pyhocon
from s3transfer import TransferConfig, S3Transfer
from openai import OpenAI
from pydub import AudioSegment

# --- Configuration & Setup ---
config = configparser.ConfigParser()
# Use /workspace/config.ini if available, otherwise handle gracefully or assume local
if os.path.exists('/workspace/config.ini'):
    config.read('/workspace/config.ini')
else:
    # Dummy config for local testing if file is missing
    config['LLM'] = {'openai_api_base': '', 'openai_api_key': ''}
    config['API Server'] = {'callback_url': '', 'updates_api_key': '', 'callback_url_2': ''}
    config['S3 Server'] = {'s3_address': '', 's3_port': '', 's3_output_bucket': '', 's3_audio_bucket': ''}
    config['HuggingFace'] = {'auth_token': ''}

base_url = config.get('LLM', 'openai_api_base')
api_key = config.get('LLM', 'openai_api_key')
client = OpenAI(api_key=api_key, base_url=base_url)
callback_url = config.get('API Server', 'callback_url')
updates_api_key = config.get('API Server', 'updates_api_key')
s3_address = config.get('S3 Server', 's3_address')
s3_port = config.get('S3 Server', 's3_port')
outputs_s3_bucket = config.get('S3 Server', 's3_output_bucket')
cat_talk_callback_url = config.get('API Server', 'callback_url_2')
hf_auth_token = config.get('HuggingFace', 'auth_token')
top_level_bucket = config.get('S3 Server', 's3_top_level_bucket')
s3_audio_bucket = config.get('S3 Server', 's3_audio_bucket')

step_count = 1
DEFAULT_LARGE_CONTEXT_MODEL = config.get('Model', 'large_model')
llm_to_openai = {DEFAULT_LARGE_CONTEXT_MODEL: "gpt-4o"}

# Global metrics
tokens_in = 0
tokens_out = 0
words_transcribed = 0

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

# --- Helper Functions ---

try:
    import tiktoken
    def count_tokens(text, model="gpt-3.5-turbo"):
        try:
            enc = tiktoken.encoding_for_model(model)
            return len(enc.encode(text))
        except:
             return len(text.split())
except ImportError:
    def count_tokens(text, model="gpt-3.5-turbo"):
        return len(text.split())

def convert_to_wav(input_path, output_path=None):
    if output_path is None:
        output_path = os.path.splitext(input_path)[0] + "_16k.wav"
    command = [
        "ffmpeg", "-y",
        "-i", input_path,
        "-ac", "1", "-ar", "16000", "-sample_fmt", "s16",
        output_path
    ]
    subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
    return output_path

def timestamp_to_seconds(ts):
    """
    Robust timestamp parser. Handles:
    HH:MM:SS.mmm
    MM:SS.mmm
    SS.mmm
    """
    if not ts or not isinstance(ts, str):
        return 0.0
    
    ts = ts.replace(',', '.').strip()
    try:
        parts = ts.split(':')
        if len(parts) == 3:
            return int(parts[0]) * 3600 + int(parts[1]) * 60 + float(parts[2])
        elif len(parts) == 2:
            return int(parts[0]) * 60 + float(parts[1])
        elif len(parts) == 1:
            return float(parts[0])
    except Exception:
        pass
    return 0.0

def format_timestamp_str(seconds):
    """Standardizes seconds to HH:MM:SS format"""
    try:
        seconds = float(seconds)
        if seconds < 0: seconds = 0
        hours = int(seconds // 3600)
        minutes = int((seconds % 3600) // 60)
        secs = int(seconds % 60)
        return f"{hours:02}:{minutes:02}:{secs:02}"
    except Exception:
        return "00:00:00"

def to_vtt_clock(sec):
    """Formats seconds to HH:MM:SS.ms for VTT."""
    if math.isnan(sec): return "00:00:00.000"
    h = math.floor(sec / 3600)
    m = math.floor((sec % 3600) / 60)
    s = math.floor(sec % 60)
    ms = max(0, round((sec - math.floor(sec)) * 1000))
    return f"{h:02d}:{m:02d}:{s:02d}.{ms:03d}"

def convert_transcript_format(default_transcript, target_format):
    segments = []
    lines = default_transcript.strip().split('\n')
    
    for line in lines:
        try:
            # Parse line format: INDEX [START - END] SPEAKER: TEXT
            parts = line.split(' [', 1)
            if len(parts) < 2: continue
            
            rest = parts[1]
            timestamp_text = rest.split('] ', 1)
            if len(timestamp_text) < 2: continue
            
            timestamps = timestamp_text[0]
            content = timestamp_text[1]
            
            start_str, end_str = timestamps.split(' - ')
            start_time = timestamp_to_seconds(start_str)
            end_time = timestamp_to_seconds(end_str)
            
            # Sanity check: Ensure end time is not before start time
            if end_time < start_time:
                end_time = start_time + 1.0

            speaker_text = content.split(': ', 1)
            if len(speaker_text) < 2:
                speaker, text = "UNKNOWN", content
            else:
                speaker, text = speaker_text[0], speaker_text[1]
                
            segments.append({
                'start': start_time,
                'end': end_time,
                'speaker': speaker,
                'text': text
            })
        except Exception as e:
            continue

    # Combine segments logic
    combined_segments = []
    for seg in segments:
        if not combined_segments:
            combined_segments.append(seg)
        else:
            last = combined_segments[-1]
            if last['speaker'] == seg['speaker'] and seg['start'] - last['end'] < 5:
                last['end'] = seg['end']
                last['text'] += ' ' + seg['text']
            else:
                combined_segments.append(seg)

    output_lines = []
    if target_format in ['WEBVTT', 'WEBVTT-caption']:
        output_lines.append('WEBVTT\n')

    display_index = 1
    
    for segment in combined_segments:
        start_time = segment['start']
        end_time = segment['end']
        text = segment['text']
        speaker = segment['speaker']
        duration = end_time - start_time
        
        # Long segment splitting logic
        if duration > 180:
            sentences = re.split(r'(?<=[.!?])\s+', text)
            total_chars = sum(len(s) for s in sentences)
            time_per_char = duration / max(total_chars, 1)
            
            chunk_start = start_time
            current_chunk_sentences = []
            current_chunk_chars = 0
            
            for idx, sentence in enumerate(sentences):
                current_chunk_sentences.append(sentence)
                current_chunk_chars += len(sentence)
                
                # Check if we should split
                chunk_text = " ".join(current_chunk_sentences).strip()
                chunk_end = chunk_start + (current_chunk_chars * time_per_char)
                
                # Split if chunk > 120s or it's the last sentence
                if (chunk_end - chunk_start >= 120) or (idx == len(sentences) - 1):
                    s_fmt = format_timestamp_str(chunk_start)
                    e_fmt = format_timestamp_str(chunk_end)
                    
                    if target_format == 'default':
                        output_lines.append(f"{display_index:02d} [{s_fmt} - {e_fmt}] {speaker}: {chunk_text}")
                    elif target_format == 'WEBVTT':
                        output_lines.append(f"{display_index}\n{s_fmt} --> {e_fmt}\n<v {speaker}> {chunk_text}\n")
                    elif target_format == 'txt':
                        output_lines.append(f"[{s_fmt}]\n{speaker}: {chunk_text}\n")
                    elif target_format == 'srt':
                        output_lines.append(f"{display_index}\n{s_fmt.replace('.',',')} --> {e_fmt.replace('.',',')}\n{chunk_text}\n")
                    
                    display_index += 1
                    chunk_start = chunk_end
                    current_chunk_sentences = []
                    current_chunk_chars = 0
        else:
            s_fmt = format_timestamp_str(start_time)
            e_fmt = format_timestamp_str(end_time)
            
            if target_format == 'default':
                output_lines.append(f"{display_index:02d} [{s_fmt} - {e_fmt}] {speaker}: {text}")
            elif target_format == 'WEBVTT':
                output_lines.append(f"{display_index}\n{s_fmt} --> {e_fmt}\n<v {speaker}> {text}\n")
            elif target_format == 'txt':
                output_lines.append(f"[{s_fmt}]\n{speaker}: {text}\n")
            elif target_format == 'bbt':
                output_lines.append(f"{text}")
            elif target_format == 'srt':
                output_lines.append(f"{display_index}\n{s_fmt.replace('.',',')} --> {e_fmt.replace('.',',')}\n{text}\n")
            display_index += 1

    return "\n".join(output_lines)

def format_transcript(json_data, format="default"):
    """Format insane-fast-whisper JSON to string"""
    
    segments = []
    if "segments" in json_data: segments = json_data["segments"]
    elif "speakers" in json_data: segments = json_data["speakers"]
    elif "word_segments" in json_data: segments = json_data["word_segments"] # fallback

    if not segments: return ""

    combined = []
    for s in segments:
        # Normalize keys
        start = s.get('start', s.get('timestamp', [0,0])[0])
        end = s.get('end', s.get('timestamp', [0,0])[1])
        text = s.get('text', '').strip()
        speaker = s.get('speaker', 'SPEAKER_00')
        
        if not combined:
            combined.append({'start': start, 'end': end, 'text': text, 'speaker': speaker})
        else:
            last = combined[-1]
            if last['speaker'] == speaker and start - last['end'] < 3.0:
                last['end'] = end
                last['text'] += " " + text
            else:
                combined.append({'start': start, 'end': end, 'text': text, 'speaker': speaker})

    # Use the convert_transcript_format logic by generating a default intermediate string
    intermediate = ""
    for idx, c in enumerate(combined):
        s_str = format_timestamp_str(c['start'])
        e_str = format_timestamp_str(c['end'])
        intermediate += f"{idx+1} [{s_str} - {e_str}] {c['speaker']}: {c['text']}\n"
        
    return convert_transcript_format(intermediate, format)


# --- Deterministic Synchronifier DP Logic ---

FILLER_RE = re.compile(r'^(well|so|now|yeah|uh|um|hmm|you know|i mean|like)[,.\s-]+', re.IGNORECASE)

def strip_fillers_start(s):
    t = s.strip()
    for _ in range(4):
        prev = t
        t = FILLER_RE.sub('', t).strip()
        if t == prev: break
    return t

def normalize_for_tokens(text):
    cleaned = re.sub(r'\[[^\]]*\]', ' ', text)
    cleaned = cleaned.replace('…', ' ').replace('...', ' ')
    cleaned = re.sub(r'<v[^>]*>', ' ', cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r'</v>', ' ', cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r'[“”„]', '"', cleaned)
    cleaned = re.sub(r'[’‘]', "'", cleaned)
    cleaned = re.sub(r'[^a-z0-9\s]', ' ', cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r'\s+', ' ', cleaned).strip().lower()
    return strip_fillers_start(cleaned)

def strip_speaker_label(par):
    return re.sub(r"^[A-Z][A-Z .,'-]{1,40}:\s*", "", par)

def paragraph_tokens(par):
    base = strip_speaker_label(par).replace('\r\n', ' ').replace('\r', ' ')
    s = normalize_for_tokens(base)
    return s.split() if s else []

def split_human_paragraphs(txt):
    clean = txt.replace('\r\n', '\n').replace('\r', '\n')
    lines = clean.split('\n')
    paras = []
    cur = []
    
    is_speaker = lambda l: bool(re.match(r"^[A-Z][A-Z .,'-]{1,40}:\s", l.strip()))
    
    for raw in lines:
        l = raw.strip()
        if not l:
            if cur:
                paras.append("\n".join(cur).strip())
                cur = []
            continue
        if is_speaker(l) and cur:
            paras.append("\n".join(cur).strip())
            cur = [l]
        else:
            cur.append(l)
    
    if cur:
        paras.append("\n".join(cur).strip())
        
    if len(paras) <= 1:
        alt = [p.strip() for p in re.split(r'\n\s*\n', clean) if p.strip()]
        if len(alt) > len(paras): return alt
        
    return paras

def align_paragraphs(paras, cues):
    """GLOBAL paragraph-cue alignment via DP."""
    P = len(paras)
    C = len(cues)
    if not P or not C: return []

    para_tokens = [paragraph_tokens(p)[:22] for p in paras]
    cue_tokens = [normalize_for_tokens(c['text']).split() for c in cues]

    para_sets = [set(toks) for toks in para_tokens]
    cue_sets = [set(toks) for toks in cue_tokens]

    sim = [[0]*C for _ in range(P)]
    for i in range(P):
        p_set = para_sets[i]
        if not p_set: continue
        for j in range(C):
            c_set = cue_sets[j]
            if not c_set: continue
            inter = len(p_set.intersection(c_set))
            sim[i][j] = inter if inter >= 2 else 0

    g = [[-math.inf] * (C + 1) for _ in range(P + 1)]
    choice = [[0] * (C + 1) for _ in range(P + 1)]
    
    for j in range(C + 1):
        g[0][j] = 0

    for i in range(1, P + 1):
        for j in range(1, C + 1):
            best = g[i][j - 1]
            ch = 0
            s = sim[i - 1][j - 1]
            cand = g[i - 1][j - 1] + s
            if cand > best:
                best = cand
                ch = 1
            g[i][j] = best
            choice[i][j] = ch

    best_score = -math.inf
    best_j = C
    for j in range(C + 1):
        if g[P][j] > best_score:
            best_score = g[P][j]
            best_j = j

    aligned = [{'paraIdx': k, 'cueIdx': 0} for k in range(P)]
    i, j = P, best_j
    while i > 0 and j > 0:
        if choice[i][j] == 1:
            aligned[i - 1] = {'paraIdx': i - 1, 'cueIdx': j - 1}
            i -= 1
            j -= 1
        else:
            j -= 1

    last_cue = 0
    for k in range(P):
        if aligned[k]['cueIdx'] < last_cue:
            aligned[k]['cueIdx'] = last_cue
        last_cue = aligned[k]['cueIdx']

    max_idx = C - 1
    for a in aligned:
        if a['cueIdx'] < 0: a['cueIdx'] = 0
        if a['cueIdx'] > max_idx: a['cueIdx'] = max_idx

    return aligned

def refine_alignment(paras, cues, aligned):
    """QUALITY CONTROL PASS - Hard/Soft Prefix Matching."""
    C = len(cues)
    prefix_len = 8
    back_scan = 10
    fwd_scan = 10
    
    refined = [dict(a) for a in aligned]
    cue_norm = [normalize_for_tokens(c['text']) for c in cues]

    for i in range(len(paras)):
        p = strip_speaker_label(paras[i])
        p_norm = normalize_for_tokens(p)
        if not p_norm: continue
        p_tokens = p_norm.split()
        if not p_tokens: continue

        pref_tokens = p_tokens[:min(prefix_len, len(p_tokens))]
        hard_prefix = " ".join(pref_tokens)
        if not hard_prefix: continue

        center = refined[i]['cueIdx']
        best_idx = center
        found_hard = False

        start = max(0, center - back_scan)
        end = min(C - 1, center + fwd_scan)

        for j in range(start, end + 1):
            c_str = cue_norm[j]
            if not c_str: continue
            if c_str.startswith(hard_prefix):
                if not found_hard:
                    best_idx = j
                    found_hard = True
                elif j < best_idx:
                    best_idx = j

        if not found_hard:
            soft_idx = center
            soft_score = -1
            for j in range(start, end + 1):
                c_set = set(cue_norm[j].split())
                ok = sum(1 for tok in pref_tokens if tok in c_set)
                if ok > soft_score:
                    soft_score = ok
                    soft_idx = j
            best_idx = soft_idx

        if i > 0 and best_idx < refined[i - 1]['cueIdx']:
            best_idx = refined[i - 1]['cueIdx']
            
        refined[i]['cueIdx'] = best_idx

    for i in range(1, len(refined)):
        if refined[i]['cueIdx'] < refined[i - 1]['cueIdx']:
            refined[i]['cueIdx'] = refined[i - 1]['cueIdx']
            
    max_idx = C - 1
    for r in refined:
        if r['cueIdx'] < 0: r['cueIdx'] = 0
        if r['cueIdx'] > max_idx: r['cueIdx'] = max_idx
        
    return refined

def parse_whisperx_transcript_lines(lines):
    """Adapter to convert standard WhisperX output into DP tool format."""
    cues = []
    for line in lines:
        if not line.strip(): continue
        parts = line.split(' [', 1)
        if len(parts) < 2: continue
        rest = parts[1]
        ts_text = rest.split('] ', 1)
        if len(ts_text) < 2: continue
        timestamps = ts_text[0]
        content = ts_text[1]
        
        try:
            start_str, end_str = timestamps.split(' - ')
            start_time = timestamp_to_seconds(start_str)
            end_time = timestamp_to_seconds(end_str)
            
            speaker_text = content.split(': ', 1)
            text = speaker_text[1] if len(speaker_text) > 1 else content
            
            cues.append({'start': start_time, 'end': end_time, 'text': text})
        except Exception as e:
            logging.warning(f"Failed to parse line for cues: {line}. Error: {e}")
            continue
    return cues

def build_outputs_multi(paras, aligned, cues):
    """Generates all requested formats using the verified alignment data."""
    N = len(paras)
    base = []
    for i in range(N):
        ci = aligned[i]['cueIdx']
        base.append(cues[ci]['start'])

    starts = [0] * N
    min_step = 0.8
    last = base[0] if not math.isnan(base[0]) else 0
    starts[0] = last

    for i in range(1, N):
        ci = aligned[i]['cueIdx']
        cue = cues[ci]
        cue_start = cue['start'] if not math.isnan(cue['start']) else last
        cue_end = cue['end'] if not math.isnan(cue['end']) else cue_start + 6

        t_base = base[i] if not math.isnan(base[i]) else cue_start
        min_allowed = last + min_step

        next_start = cue_end
        if ci < len(cues) - 1:
            ns = cues[ci + 1]['start']
            if not math.isnan(ns):
                next_start = max(cue_start + 1, ns)
                
        max_allowed = max(min_allowed, next_start - 0.5)

        t = t_base
        if t < min_allowed: t = min_allowed
        if t > max_allowed: t = max_allowed
        if math.isnan(t): t = min_allowed
        if t < min_allowed: t = min_allowed

        starts[i] = t
        last = t

    out_default = []
    out_vtt = ["WEBVTT\n"]
    out_txt = []
    out_bbt = []
    out_srt = []
    
    for i in range(N):
        start = starts[i]
        ci = aligned[i]['cueIdx']
        cue = cues[ci]
        cue_start = cue['start'] if not math.isnan(cue['start']) else start
        cue_end_ref = cue['end'] if not math.isnan(cue['end']) else cue_start + 6

        end = cue_end_ref
        if i + 1 < N:
            next_start = starts[i + 1]
            if not math.isnan(next_start):
                end = min(end, next_start - 0.5)
                
        if math.isnan(end) or end <= start + 0.2:
            end = start + 3.5

        # Extract speaker if available
        speaker = "UNKNOWN"
        m = re.match(r"^([A-Z][A-Z .,'-]{1,40}):\s*(.*)", paras[i], flags=re.DOTALL)
        if m:
            speaker = m.group(1)
            text = m.group(2).replace('\n', ' ').replace('\r', '').strip()
        else:
            text = paras[i].replace('\n', ' ').replace('\r', '').strip()

        s_fmt = format_timestamp_str(start)
        e_fmt = format_timestamp_str(end)
        s_vtt = to_vtt_clock(start)
        e_vtt = to_vtt_clock(end)
        s_srt = s_vtt.replace('.', ',')
        e_srt = e_vtt.replace('.', ',')
        display_index = i + 1
        
        out_default.append(f"{display_index:02d} [{s_fmt} - {e_fmt}] {speaker}: {text}")
        out_vtt.append(f"{display_index}\n{s_vtt} --> {e_vtt}\n<v {speaker}> {text}\n")
        out_txt.append(f"[{s_fmt}]\n{paras[i]}\n")
        out_bbt.append(text)
        out_srt.append(f"{display_index}\n{s_srt} --> {e_srt}\n{text}\n")
        
    return {
        'default': "\n".join(out_default),
        'WEBVTT': "\n".join(out_vtt),
        'txt': "\n".join(out_txt),
        'bbt': "\n".join(out_bbt),
        'srt': "\n".join(out_srt)
    }

# --- Classes ---

class SynchronifierProcessor:
    def __init__(self, openai_client=None):
        self.client = openai_client or client

    def verbatimize(self, file_path, outputs_dir):
        # Wrapper for existing WhisperX logic provided in original code
        return verbatimize(file_path, outputs_dir)

    def process_transcript(self, transcript_lines, transcript2_lines, outputs_local_dir, base_name="test"):
        logging.info("Starting local DP alignment of human and machine transcripts...")
        
        # transcript_lines = machine verbatim lines
        # transcript2_lines = legacy human lines
        cues = parse_whisperx_transcript_lines(transcript_lines)
        legacy_text = "\n".join(transcript2_lines)
        paras = split_human_paragraphs(legacy_text)
        
        if not paras:
            logging.error("No speaker paragraphs detected in the human transcript.")
            return None, 1
        if not cues:
            logging.error("No valid timecodes found in the machine reference file.")
            return None, 1
            
        logging.info(f"Parsed {len(paras)} human paragraphs and {len(cues)} machine cues. Running Global DP Alignment...")
        base_aligned = align_paragraphs(paras, cues)
        
        logging.info("Running Quality Control (Hard/Soft Prefix Matching)...")
        refined = refine_alignment(paras, cues, base_aligned)
        
        logging.info("Alignment successful. Exporting formats...")
        outputs = build_outputs_multi(paras, refined, cues)
        
        output_path = os.path.join(outputs_local_dir, base_name)
        
        formats = [
            ('.transcript', 'default'),
            ('_trnd_mach.vtt', 'WEBVTT'),
            ('_trnd_mach.txt', 'txt'),
            ('_trnd_mach_bbt.txt', 'bbt'),
            ('_trnd_machine_capt.vtt', 'srt')
        ]
        
        for ext, fmt in formats:
            with open(output_path + ext, 'w', encoding='utf-8') as f:
                f.write(outputs[fmt])
                
        logging.info(f"Successfully generated 5 transcript formats for {base_name}.")
        return base_name, 0

# --- Standalone Functions needed for Main Block ---

def update_status(file_id, job, status, message, update_url):
    headers = {'Authorization': f'Bearer {updates_api_key}', 'Content-Type': 'application/json'}
    payload = {'status': status, 'job': job, 'file_id': file_id, 'message': message}
    try:
        requests.post(update_url, headers=headers, json=payload)
    except: pass

def update_metrics(task_id, job_type, parameters, metrics_url):
    headers = {'Authorization': f'Bearer {updates_api_key}', 'Content-Type': 'application/json'}
    payload = {'task_id': task_id, 'job_type': job_type, 'parameters': parameters}
    try:
        requests.post(metrics_url, headers=headers, json=payload)
    except: pass

def verbatimize(file_path, outputs_dir):
    try:
        import whisperx
        import torch
        device = "cuda" if torch.cuda.is_available() else "cpu"
        model = whisperx.load_model("large-v3", device, compute_type="float16" if device=="cuda" else "float32")
        audio = whisperx.load_audio(file_path)
        result = model.transcribe(audio, batch_size=16)
        model_a, metadata = whisperx.load_align_model(language_code=result["language"], device=device)
        result = whisperx.align(result["segments"], model_a, metadata, audio, device, return_char_alignments=False)
        
        # Save JSON
        base_name = os.path.splitext(os.path.basename(file_path))[0]
        with open(os.path.join(outputs_dir, f'{base_name}.json'), 'w') as f:
            json.dump(result, f)
        
        # Create initial verbatim transcript
        with open(os.path.join(outputs_dir, f'{base_name}.transcript'), 'w') as f:
            f.write(format_transcript(result))
        return 0
    except Exception as e:
        print(traceback.format_exc())
        return 1

# --- Main Execution Block ---

if __name__ == '__main__':
    try:
        print('Starting Train Script')
        parser = argparse.ArgumentParser(description='LLM Factory Agent')

        # General arguments
        parser.add_argument('--project_name', type=str, default='SpeakEZ', help='name of clearml project')
        parser.add_argument('--task_name', type=str, default='synchronifier_task', help='name of clearml task')
        parser.add_argument('--project_id', type=str, default='TEMPLATE_DATASET_DO_NOT_REMOVE', help='the id of the project and the name of the dataset in clearml')
        parser.add_argument('--collection_id', type=str, default='TEMPLATE_COLLECTION_DO_NOT_REMOVE', help='collection id')

        # Dataset parameters
        parser.add_argument('--dataset_project', type=str, default='SpeakEZ_Datasets', help='clearml project location of dataset')
        parser.add_argument('--file_id', type=str, default='fitnessgram', help='clearml dataset file id')
        parser.add_argument('--file_extension', type=str, default='.mp3', help='file extension of the audio file')
        parser.add_argument('--transcript_file_name', type=str, default='fitnessgram_synchronifier.txt', help='transcript file name without timestamps')
        parser.add_argument('--audio_file_name', type=str, default='fitnessgram.mp3', help='audio file name')
        parser.add_argument('--synchronifier_transcript_file', type=str, default='fitnessgram_synchronifier.txt', help='transcript without timestamps')
        parser.add_argument('--noDelete', action='store_true', help='Disable deletion of audio files (default: False)')

        parser.add_argument('--job_id', type=str, help='id of job')

        args = parser.parse_args()

        print('Starting ClearML Task')
        task = Task.init(project_name=args.project_name, task_name=args.task_name, output_uri="s3://s3.ai.uky.edu:443/cat-talk")
        task_id = str(task.current_task().id)
        print('Task_id:', task_id)

        clearml_dataset_name = f'{args.project_id}_{args.file_id}_outputs'
        clearml_synchronifier_dataset_name = f'{args.project_id}_{args.synchronifier_transcript_file}'
        print(f'clearml_dataset_name : {clearml_dataset_name}')
        print(f'clearml_synchronifier_dataset_name: {clearml_synchronifier_dataset_name}')

        # pull the audio file from s3 and cache local to DGX (like verbatimizer)
        clearml_dataset_name = f'{args.project_id}_{args.file_id}'
        clearml_dataset = Dataset.get(dataset_name=clearml_dataset_name, dataset_project=args.dataset_project)
        dataset_cache_path = clearml_dataset.get_local_copy()

        clearml_synchronifier_dataset = Dataset.get(dataset_name=clearml_synchronifier_dataset_name, dataset_project=args.dataset_project)
        synchronifier_dataset_cache_path = clearml_synchronifier_dataset.get_local_copy()

        print('ENVS:')
        for name, value in os.environ.items():
            print("{0}: {1}".format(name, value))

        print('\nARGS:')
        for name, value in vars(args).items():
            print(f'{name} = {value}')

        control_node = False
        if "PMIX_RANK" in os.environ:
            if os.environ["PMIX_RANK"] == '0':
                control_node = True
        else:
            control_node = True

        outputs_local_dir = os.environ.get('OUTPUT_DIR')
        if not os.path.exists(outputs_local_dir):
            os.mkdir(outputs_local_dir)
        print([args.file_id, outputs_local_dir])
        print(f'ls {dataset_cache_path}:')
        print(os.listdir(dataset_cache_path), end='\n\n')
        print(f'ls {synchronifier_dataset_cache_path}:')
        print(os.listdir(synchronifier_dataset_cache_path), end='\n\n')
        audio_file_name = args.file_id + args.file_extension
        audio_file_path = os.path.join(dataset_cache_path, audio_file_name)
        legacy_transcript_path = os.path.join(synchronifier_dataset_cache_path, args.synchronifier_transcript_file)
        print(f'dataset_cache_path: {dataset_cache_path}')
        print(f'args.file_id: {args.file_id}')
        print(f'audio_file_path: {audio_file_path}')
        print(f'args.collection_id: {args.collection_id}')

        # attempt to convert file to mp3
        try:
            convert_to_wav(audio_file_path, audio_file_path)
        except Exception as e:
            print(e)

        # --- Synchronify process: transcribe, then synchronify ---
        processor = SynchronifierProcessor()
        verbatimize_rc = processor.verbatimize(audio_file_path, outputs_local_dir)
        if verbatimize_rc != 0:
            raise ValueError(f"Verbatimize failed with return code {verbatimize_rc}")
        base_name = os.path.splitext(os.path.basename(audio_file_path))[0]
        transcript_path = os.path.join(outputs_local_dir, f'{base_name}.transcript')
        with open(transcript_path, 'r', encoding='utf-8') as f:
            verbatimized_transcript = f.read().splitlines()
        with open(legacy_transcript_path, 'r', encoding='utf-8', errors="ignore") as f:
            legacy_transcript = f.read().splitlines()
            
        new_filename, synchronify_rc = processor.process_transcript(verbatimized_transcript, legacy_transcript, outputs_local_dir, base_name=base_name)
        if synchronify_rc != 0:
            raise ValueError(f"Synchronify failed with return code {synchronify_rc}")
       

        print("\nGenerated files:")
        for fname in os.listdir(outputs_local_dir):
            print(" -", fname)
        # Print the contents of all generated files
        print("\n--- File Contents ---")
        for fname in os.listdir(outputs_local_dir):
            fpath = os.path.join(outputs_local_dir, fname)
            print(f"\n>>> {fname} <<<")
            try:
                with open(fpath, "r", encoding="utf-8") as f:
                    content = f.read()
                    print(content)
            except Exception as e:
                print(f"[Could not read file: {e}]")

        if (args.job_id is not None):

            # remove checkpoints
            Logger.current_logger().report_text("Uploading transcriptions.", print_console=True)

            clearml_config_path = os.environ['CLEARML_CONFIG_FILE']
            config = pyhocon.ConfigFactory.parse_file(clearml_config_path)
            for record in config['sdk']['aws']['s3']['credentials']:
                if record['bucket'] == top_level_bucket:
                    s3_endpoint = 'https://' + record['host']
                    s3_key = record['key']
                    s3_secret = record['secret']

            myconfig = TransferConfig(
                multipart_threshold=9999999999999999,  # workaround for 'disable' auto multipart upload
                max_concurrency=10,
                num_download_attempts=10,
            )

            s3_client = boto3.client('s3',
                                        endpoint_url=s3_endpoint,
                                        aws_access_key_id=s3_key,
                                        aws_secret_access_key=s3_secret,
                                        aws_session_token=None)
            print(s3_endpoint)

            clearml_source_urls = []
            files_to_delete = []

            # Create new dataset for outputs
            output_clearml_dataset_name = f'{args.project_id}_{args.file_id}_outputs'
            output_dataset = Dataset.create(
                dataset_name=output_clearml_dataset_name, dataset_project=args.dataset_project
            )

            transfer = S3Transfer(s3_client, myconfig)
            print('Uploading risk analysis: ', outputs_local_dir)
            dataset_files = [f for f in os.listdir(outputs_local_dir) if isfile(join(outputs_local_dir, f))]
            for dataset_file in dataset_files:
                print(f'dataset_file: {dataset_file}')
                local_dataset_path = os.path.join(outputs_local_dir, dataset_file)
                print(f'local_dataset_path: {local_dataset_path}')
                remote_dataset_path = outputs_s3_bucket + '/' + args.project_id + '/' + args.collection_id + '/' + dataset_file
                print(f'remote_dataset_path: {remote_dataset_path}')
                clearml_source_urls.append(f's3://{s3_address}:{s3_port}/{top_level_bucket}/{remote_dataset_path}')
                try:
                    response = transfer.upload_file(local_dataset_path, top_level_bucket, remote_dataset_path)
                except:
                    with botocore.exceptions.ClientError as e:
                        print(e.response['Error']['Message'])
                        print(e.response['Error'])

                # Defer deletion until AFTER the ClearML dataset finalize method runs
                files_to_delete.append(local_dataset_path)
                print(f'response: {response}')

            output_dataset.add_external_files(source_url = clearml_source_urls, verbose=True)
            output_dataset.upload()
            output_dataset.finalize()

            # Execute the deferred cleanup safely now that ClearML is done profiling the files
            for f in files_to_delete:
                try:
                    os.remove(f)
                except Exception as e:
                    print(f"Error deleting local file {f}: {e}")

            # After all this is finalized, delete the original audio file
            if not args.noDelete:
                try:
                    delete_path = args.project_id + '/' + args.collection_id + '/' + args.file_id + args.file_extension
                    response = s3_client.delete_object(Bucket=s3_audio_bucket.strip("'\""), Key=delete_path)
                    print(f'delete file response: {response}')
                except Exception as e:
                    print(f"Error deleting file {remote_dataset_path}: {e}")

        else:
            raise Exception('Dataset preparation failed!')

        task.close()

        synchronifier_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'synchronifier', 'complete', 'Ready for further analysis', synchronifier_update_url)

        # Calculate minutes_audio
        try:
            audio = AudioSegment.from_file(audio_file_path)
            minutes_audio = audio.duration_seconds / 60
            print(f'Synchronifier: minutes_audio calculated as {minutes_audio}')
        except Exception as e:
            print(f"Error calculating audio duration: {e}")
            minutes_audio = 0

        print(f'Synchronifier: minutes_audio: {minutes_audio}, tokens_in: {tokens_in}, tokens_out: {tokens_out}')
        update_metrics(task_id, 'synchronify', {
            'minutes_audio': minutes_audio,
            'words_transcribed': words_transcribed,
            'tokens_in': tokens_in,
            'tokens_out': tokens_out
        }, cat_talk_callback_url + '/metrics/update')

    except Exception as e: # if jobs fails
        print(e)
        traceback.print_exc()
        riskalyze_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'synchronifier', 'failed', '', riskalyze_update_url)
