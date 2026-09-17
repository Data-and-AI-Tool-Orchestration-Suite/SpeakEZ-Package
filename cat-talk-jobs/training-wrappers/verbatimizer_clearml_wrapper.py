import os
os.environ["TORCH_FORCE_NO_WEIGHTS_ONLY_LOAD"] = "1"

import argparse
import json
import os
import re
from os.path import isfile, join
import whisperx

import pandas as pd
from clearml import Task, Dataset
from clearml import Logger
from pathlib import Path

from clearml.utilities import pyhocon
import boto3 # type: ignore
from s3transfer import TransferConfig, S3Transfer # type: ignore
import botocore.exceptions

import configparser
import requests
import traceback

from pydub import AudioSegment

import re
import os
from pathlib import Path
import configparser
import torch
import logging
import subprocess

config = configparser.ConfigParser()
config.read('/workspace/config.ini')
callback_url = config.get('API Server', 'callback_url')
cat_talk_callback_url = config.get('API Server', 'callback_url_2')
updates_api_key = config.get('API Server', 'updates_api_key')
s3_address = config.get('S3 Server', 's3_address')
s3_port = config.get('S3 Server', 's3_port')
top_level_bucket = config.get('S3 Server', 's3_top_level_bucket')
audio_bucket = config.get('S3 Server', 's3_audio_bucket')
outputs_s3_bucket = config.get('S3 Server', 's3_output_bucket')
hf_auth_token = config.get('HuggingFace', 'auth_token')

words_transcribed = 0

# --- Logging Setup ---
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)


step_count = 1

user_home = str(Path.home())

def format_transcript(json_data, format="default"):
    """
    Format transcript data from insanely-fast-whisper output into various formats for Verbatimizer.

    Args:
        json_data (dict): The JSON data from insanely-fast-whisper
        format (str): Output format ('default', 'WEBVTT', 'WEBVTT-caption', 'txt', 'bbt', 'srt')

    Returns:
        str: The formatted transcript as a string in the specified format.
    """
    # Helper function to format timestamps
    def format_timestamp(seconds, use_comma=False):
        """Formats seconds as HH:MM:SS.mmm (WebVTT) or HH:MM:SS,mmm (SRT)."""
        if seconds is None:
            return "00:00:00.000"
        try:
            hours = int(float(seconds) // 3600)
            minutes = int((float(seconds) % 3600) // 60)
            secs = float(seconds) % 60
            milliseconds = int((secs - int(secs)) * 1000)
            formatted_time = f"{hours:02}:{minutes:02}:{int(secs):02}.{milliseconds:03d}"
            
            return formatted_time.replace('.', ',') if use_comma else formatted_time
        except (TypeError, ValueError):
            print(f"Warning: Invalid timestamp value: {seconds}")
            return "00:00:00.000"

    output_lines = []

    # Initialize WEBVTT header if needed
    if format in ['WEBVTT', 'WEBVTT-caption']:
        output_lines.append('WEBVTT\n')

    # Get segments data - try different possible structures
    segments = []
    if "segments" in json_data:
        segments = json_data["segments"]
    elif "speakers" in json_data:
        segments = json_data["speakers"]
    elif "chunks" in json_data:
        segments = [
            {
                "speaker": "SPEAKER_00",  # Default speaker if not specified
                "timestamp": chunk.get("timestamp", [0, 0]),
                "text": chunk.get("text", "")
            }
            for chunk in json_data["chunks"]
        ]

    if not segments:
        print("Warning: No valid segments found in JSON data")
        return ""

    # First, combine consecutive segments from the same speaker
    combined_segments = []
    for seg in segments:
        start_time = seg.get("start", 0)
        end_time = seg.get("end", 0)
        text = seg.get("text", "").strip()
        speaker = seg.get("speaker", "SPEAKER_00")
        if not combined_segments:
            combined_segments.append({"start": start_time, "end": end_time, "text": text, "speaker": speaker})
        else:
            last = combined_segments[-1]
            # If same speaker and gap < 5s, combine
            if last["speaker"] == speaker and start_time - last["end"] < 5:
                last["end"] = end_time
                last["text"] += " " + text
            else:
                combined_segments.append({"start": start_time, "end": end_time, "text": text, "speaker": speaker})

    display_index = 0

    # Helper for sentence splitting
    def split_sentences(text):
        return re.split(r'(?<=[.!?])\s+', text)

    for i, segment in enumerate(combined_segments):
        try:
            start_time = segment["start"]
            end_time = segment["end"]
            text = segment["text"].strip()
            speaker = segment["speaker"]

            if not text:
                continue
            if start_time == end_time:
                continue

            duration = end_time - start_time

            # If segment is longer than 3 minutes, break it up
            if duration > 180:
                sentences = split_sentences(text)
                total_chars = sum(len(s) for s in sentences)
                time_per_char = duration / max(total_chars, 1)

                chunk_start = start_time
                chunk_text = ""
                chunk_chars = 0
                chunk_sentences = []
                chunk_count = 0
                for idx, sentence in enumerate(sentences):
                    chunk_sentences.append(sentence)
                    chunk_chars += len(sentence)
                    chunk_text = " ".join(chunk_sentences).strip()
                    chunk_end = chunk_start + chunk_chars * time_per_char
                    remaining_chars = total_chars - chunk_chars
                    remaining_time = duration - (chunk_end - start_time)
                    next_chunk_time = remaining_time if remaining_chars > 0 else 0
                    if (chunk_end - chunk_start >= 120 and next_chunk_time >= 60) or idx == len(sentences) - 1:
                        chunk_speaker = speaker if chunk_count == 0 else f"{speaker} CONT."
                        start_formatted = format_timestamp(chunk_start, use_comma=(format == 'srt'))
                        end_formatted = format_timestamp(chunk_end, use_comma=(format == 'srt'))
                        if format == 'default':
                            output_lines.append(f"{display_index+1} [{start_formatted} - {end_formatted}] {chunk_speaker}: {chunk_text}")
                        elif format == 'WEBVTT':
                            output_lines.append(f"{display_index+1}\n{start_formatted} --> {end_formatted}\n<v {chunk_speaker}> {chunk_text}\n")
                        elif format == 'txt':
                            output_lines.append(f"[{start_formatted}]\n{chunk_speaker}: {chunk_text}\n")
                        elif format == 'bbt':
                            output_lines.append(f"{chunk_text}\n")
                        elif format == 'srt':
                            output_lines.append(f"{display_index+1}\n{start_formatted} --> {end_formatted}\n{chunk_text}\n")
                        display_index += 1
                        chunk_count += 1
                        chunk_start = chunk_end
                        chunk_sentences = []
                        chunk_chars = 0
                continue

            start_formatted = format_timestamp(start_time, use_comma=(format == 'srt'))
            end_formatted = format_timestamp(end_time, use_comma=(format == 'srt'))
            if format == 'default':
                output_lines.append(f"{display_index+1} [{start_formatted} - {end_formatted}] {speaker}: {text}")
            elif format == 'WEBVTT':
                output_lines.append(f"{display_index+1}\n{start_formatted} --> {end_formatted}\n<v {speaker}> {text}\n")
            elif format == 'txt':
                output_lines.append(f"[{start_formatted}]\n{speaker}: {text}\n")
            elif format == 'bbt':
                output_lines.append(f"{text}\n")
            elif format == 'srt':
                output_lines.append(f"{display_index+1}\n{start_formatted} --> {end_formatted}\n{text}\n")
            display_index += 1
        except Exception as e:
            print(f"Warning: Error processing segment {i}: {str(e)}")
            continue
    return "\n".join(output_lines)

def convert_to_wav(input_path, output_path=None):
    import tempfile
    import shutil
    if output_path is None:
        output_path = os.path.splitext(input_path)[0] + "_16k.wav"

    # Always use a temp file for ffmpeg output
    with tempfile.NamedTemporaryFile(suffix="_16k.wav", delete=False) as tmp_wav:
        tmp_wav_path = tmp_wav.name

    command = [
        "ffmpeg", "-y",
        "-i", input_path,
        "-ac", "1",              # mono
        "-ar", "16000",          # 16 kHz
        "-sample_fmt", "s16",    # 16-bit PCM
        tmp_wav_path
    ]

    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    print('ffmpeg stdout:', result.stdout)
    print('ffmpeg stderr:', result.stderr)
    if result.returncode != 0:
        print(f'ffmpeg failed with exit code {result.returncode}')
        # Clean up temp file
        try:
            os.remove(tmp_wav_path)
        except Exception:
            pass
        raise subprocess.CalledProcessError(result.returncode, command, output=result.stdout, stderr=result.stderr)

    # Copy temp file to output_path if needed
    if tmp_wav_path != output_path:
        shutil.copyfile(tmp_wav_path, output_path)
        os.remove(tmp_wav_path)
    return output_path

def create_transcript_file(merged_file_path, general_transcript_path):
    """
    Create transcript files in multiple formats from a merged JSON transcript file.

    Args:
        merged_file_path (str): Path to the merged JSON transcript file.
        general_transcript_path (str): Base path for output transcript files.
    """
    with open(merged_file_path, 'r', encoding='utf-8') as merged_file:
        content = merged_file.read()
        content_json = json.loads(content)

    with open(general_transcript_path+'.transcript', 'w', encoding='utf-8') as transcript_file:
        transcript_file.write(format_transcript(content_json))

    with open(general_transcript_path+'_trnd_mach.vtt', 'w', encoding='utf-8') as transcript_file:
        transcript_file.write(format_transcript(content_json, 'WEBVTT'))

    with open(general_transcript_path+'_trnd_mach.txt', 'w', encoding='utf-8') as transcript_file:
        transcript_file.write(format_transcript(content_json, 'txt'))

    with open(general_transcript_path+'_trnd_mach_bbt.txt', 'w', encoding='utf-8') as transcript_file:
        transcript_file.write(format_transcript(content_json, 'bbt'))

    with open(general_transcript_path+'_trnd_machine_capt.vtt', 'w', encoding='utf-8') as transcript_file:
        transcript_file.write(format_transcript(content_json, 'srt'))


def extract_string_between_curly_braces(text):
    """
    Extract the first substring found between curly braces in a string.

    Args:
        text (str): The input string.

    Returns:
        str or None: The extracted substring, or None if not found.
    """
    match = re.search(r'\{(.*?)\}', text)
    if match:
        return match.group(1)
    else:
        return None

def update_status(file_id, job, status, message, update_url):
    """
    Update the status of a job by sending a POST request to the specified update URL.

    Args:
        file_id (str): The file identifier.
        job (str): The job name.
        status (str): The status to report.
        message (str): Additional message to include.
        update_url (str): The URL to send the update to.
    """
    headers = {
        'Authorization': f'Bearer {updates_api_key}',
        'Content-Type': 'application/json'  # Ensure the content type is JSON
    }

    payload = {
        'status': status,
        'job': job,
        'file_id': file_id,
        'message': message
    }
    try:
        response = requests.post(update_url, headers=headers, json=payload)
        print(response.json())
        response.raise_for_status()  # Raise an error for bad responses
    except requests.RequestException as e:
        print(f"Error updating status: {e}")

def update_metrics(task_id, job_type, parameters, metrics_url):
    headers = {
        'Authorization': f'Bearer {updates_api_key}',
        'Content-Type': 'application/json'  # Ensure the content type is JSON  
    }

    payload = {
        'task_id': task_id,
        'job_type': job_type,
        'parameters': parameters
    }

    try:
        logging.info(f"Updating metrics with payload: {payload}")
        logging.info(f"Metrics URL: {metrics_url}")
        response = requests.post(metrics_url, headers=headers, json=payload)
        try:
            logging.info("Response JSON:")
            logging.info(response.json())
        except:
            logging.info("Response text:")
            logging.info(response.text)
            response.raise_for_status()  # Raise an error for bad responses
    except requests.RequestException as e:
        logging.error(f"Error updating metrics: {e}")


'''
Process for Verbatimizing
'''
def verbatimize(file_path, outputs_dir):
    """
    Transcribe and diarize audio using WhisperX, then save results in various formats.

    Args:
        file_path (str): Path to the audio file.
        outputs_dir (str): Directory to save output files.

    Returns:
        int: 0 if successful, 1 if an error occurred.
    """
    try:
        device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"Using device: {device}")
        compute_type = "float16" if device == "cuda" else "float32"

        # Create output file paths
        base_name = os.path.splitext(os.path.basename(file_path))[0]
        filename = f'{base_name}.json'
        output_json = os.path.join(outputs_dir, filename)

        # Load WhisperX model
        model = whisperx.load_model("large-v3", device, compute_type=compute_type)
        audio = whisperx.load_audio(file_path)
        result = model.transcribe(audio, batch_size=16)

        # Align transcription
        model_a, metadata = whisperx.load_align_model(language_code=result["language"], device=device)
        result = whisperx.align(result["segments"], model_a, metadata, audio, device, return_char_alignments=False)
        
        # Diarization
        diarize_model = whisperx.diarize.DiarizationPipeline(use_auth_token=hf_auth_token, device=device, model_name="pyannote/speaker-diarization-3.1")
        diarize_segments = diarize_model(audio, num_speakers=2)
        result = whisperx.assign_word_speakers(diarize_segments, result)

        global words_transcribed
        try:
            words_transcribed = len(result['word_segments'])
        except:
            words_transcribed = 0

        # Save JSON output
        with open(output_json, 'w', encoding='utf-8') as f:
            json.dump(result, f, indent=2)

        general_trascript_path = f'{outputs_dir}/{os.path.splitext(os.path.basename(file_path))[0]}'
        # Save TXT output
        create_transcript_file(output_json, general_trascript_path)

        return 0

    except Exception as e:
        print(f'Error during transcription: {e}')
        print(traceback.format_exc())
        return 1


if __name__ == '__main__':
    try:
        print('Starting Train Script')
        parser = argparse.ArgumentParser(description='LLM Factory Agent')

        # General arguments
        parser.add_argument('--project_name', type=str, default='SpeakEZ', help='name of clearml project')
        parser.add_argument('--task_name', type=str, default='verbatimizer_task', help='name of clearml task')
        parser.add_argument('--dataset_path', type=str, default='/workspace/custom_data', help='location of dataset') # this is necessary but idk what it does
        parser.add_argument('--project_id', type=str, default='TEMPLATE_DATASET_DO_NOT_REMOVE', help='the id of the project and the name of the dataset in clearml')
        parser.add_argument('--collection_id', type=str, default='TEMPLATE_COLLECTION_DO_NOT_REMOVE', help='collection id')
        parser.add_argument('--file_extension', type=str, default='.mp3', help='extension of the audiofile')
        parser.add_argument('--llm_guess_weight', type=float, default=0, help='LLM guessing which speaker is talking')

        # Dataset parameters
        parser.add_argument('--dataset_project', type=str, default='SpeakEZ_Datasets', help='clearml project location of dataset')
        # parser.add_argument('--dataset_name', type=str, default='oral_history', help='clearml dataset name')
        parser.add_argument('--file_id', type=str, default='fitnessgram', help='clearml dataset file id')
        # parser.add_argument('--outputs_dir', type=str, default='./outputs', help='directory of outputs of each verbatimizer step')

        #parser.add_argument('--clearml_cache', type=str, default=os.path.join(user_home,'.clearml/cache'), help='location of dataset')
        #parser.add_argument('--clearml_cache', type=str, default='/app/cache', help='location of dataset')
        parser.add_argument('--job_id', type=str, help='id of job')
        parser.add_argument('--noDelete', action='store_true', help='Disable deletion of audio files (default: False)')


        args = parser.parse_args()

        print('\nENVS:')
        for name, value in os.environ.items():
            print("{0}: {1}".format(name, value))

        print('\nARGS:')
        for name, value in vars(args).items():
            print(f'{name} = {value}')

        print('\nStarting ClearML Task')
        task = Task.init(project_name=args.project_name, task_name=args.task_name, output_uri='s3://s3.ai.uky.edu:443/cat-talk')
        task_id = str(task.current_task().id)
        print('Task_id:', task_id)

        custom_task_data_path = os.path.join(args.dataset_path, task_id)
        outputs_local_dir = os.environ.get('OUTPUT_DIR')

        clearml_dataset_name = f'{args.project_id}_{args.file_id}'
        # pull the file to verbatimize from s3 and cache locally
        clearml_dataset = Dataset.get(dataset_name=clearml_dataset_name, dataset_project=args.dataset_project)
        dataset_cache_path = clearml_dataset.get_local_copy()

        if dataset_cache_path is not None:
            print('Dataset is prepared successfully. Starting training...')

            #allow script to cleanup on failure
            os.environ["CLEARML_CUSTOM_TASK_DATA_PATH"] = custom_task_data_path

            audio_file_path = os.path.join(dataset_cache_path, args.file_id+args.file_extension)
            
            # attempt to convert file to wav
            try:
                convert_to_wav(audio_file_path, audio_file_path)
            except Exception as e:
                print(e)

            # Calculate minutes of audio
            try:
                audio = AudioSegment.from_file(audio_file_path)
                minutes_audio = audio.duration_seconds / 60
            except Exception as e:
                print(f"Error calculating audio duration: {e}")
                minutes_audio = 0

            verbatimize_rc = verbatimize(audio_file_path, outputs_local_dir)
            if verbatimize_rc != 0:
                raise ValueError(f"transcription and diarization failed with code {verbatimize_rc}")
            
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


            if args.job_id is not None:

                Logger.current_logger().report_text("Uploading transcriptions.", print_console=True)

                s3_bucket = 'cat-talk'
                sub_dir = 'output'
                #os.environ['CLEARML_CONFIG_FILE']
                #clearml_config_path = os.path.join(os.path.expanduser('~'), 'clearml.conf')
                clearml_config_path = os.environ['CLEARML_CONFIG_FILE']
                config = pyhocon.ConfigFactory.parse_file(clearml_config_path)
                for record in config['sdk']['aws']['s3']['credentials']:
                    if record['bucket'] == s3_bucket:
                        s3_endpoint = 'https://' + record['host']
                        s3_key = record['key']
                        s3_secret = record['secret']

                myconfig = TransferConfig(

                    multipart_threshold=9999999999999999,  # workaround for 'disable' auto multipart upload
                    # multipart_threshold=1,  # workaround for 'disable' auto multipart upload
                    max_concurrency=10,
                    num_download_attempts=10,
                )

                s3_client = boto3.client('s3',
                                        endpoint_url=s3_endpoint,
                                        aws_access_key_id=s3_key,
                                        aws_secret_access_key=s3_secret,
                                        aws_session_token=None)
                print(s3_endpoint)

                transfer = S3Transfer(s3_client, myconfig)

                print('Uploading transcriptions: ', outputs_local_dir)
                dataset_files = [f for f in os.listdir(outputs_local_dir) if isfile(join(outputs_local_dir, f))]

                clearml_source_urls = []
                clearml_dataset_paths = []

                # Create new dataset for outputs
                output_clearml_dataset_name = f'{args.project_id}_{args.file_id}_outputs'
                output_dataset = Dataset.create(
                    dataset_name=output_clearml_dataset_name, dataset_project=args.dataset_project
                )

                for dataset_file in dataset_files:
                    print(f'dataset_file: {dataset_file}')
                    local_dataset_path = os.path.join(outputs_local_dir, dataset_file)
                    print(f'local_dataset_path: {local_dataset_path}')
                    remote_dataset_path = sub_dir + '/' + args.project_id + '/' + args.collection_id + '/' + dataset_file
                    print(f'remote_dataset_path: {remote_dataset_path}')
                    clearml_source_urls.append(f's3://{s3_address}:{s3_port}/{top_level_bucket}/{remote_dataset_path}')
                    # clearml_dataset_paths.append('')
                    try:
                            response = transfer.upload_file(local_dataset_path, s3_bucket, remote_dataset_path)
                    except:
                        with botocore.exceptions.ClientError as e:
                            print(e.response['Error']['Message'])
                            print(e.response['Error'])
                    print(f'response: {response}')
                output_dataset.add_external_files(source_url = clearml_source_urls, verbose=True)
                output_dataset.upload()
                output_dataset.finalize()

                # After all this is finalized, delete the original audio file
                if not args.noDelete:
                    try:
                        delete_path = args.project_id + '/' + args.collection_id + '/' + args.file_id + args.file_extension
                        response = s3_client.delete_object(Bucket=audio_bucket.strip("'\""), Key=delete_path)
                        print(f'delete file response: {response}')
                    except Exception as e:
                        print(f"Error deleting file {remote_dataset_path}: {e}")
                
        else:
            raise Exception('Dataset preparation failed!')


        verbatimize_update_url = callback_url + '/updates/verbatimizer'
        update_status(args.file_id, 'verbatimizer', 'complete', 'Ready for analysis', verbatimize_update_url)

        verbatimizer_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'verbatimizer', 'complete', 'Ready for analysis', verbatimizer_update_url)

        print(f'Verbatimization complete, updating metrics with:\nminutes_audio: {minutes_audio}')
        update_metrics(task_id, 'transcribe', {
            'minutes_audio': minutes_audio,
            'words_transcribed': words_transcribed,
        }, cat_talk_callback_url + '/metrics/update')

    except Exception as e: # if the job fails
        print(e)
        traceback.print_exc()
        verbatimize_update_url = callback_url + '/updates/verbatimizer'
        update_status(args.file_id, 'verbatimizer', 'failed', '', verbatimize_update_url)

        verbatimizer_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'verbatimizer', 'failed', '', verbatimizer_update_url)

    task.close()
