import re
import os
import sys
import shutil
import time
import datetime
from pathlib import Path
import json
import numpy as np
import configparser

from openai import OpenAI


config = configparser.ConfigParser()
config.read('config.ini')
openai_api_key = config.get('LLM', 'openai_api_key')
openai_api_base = config.get('LLM', 'openai_api_base')

adapter_id = ""

client = OpenAI(
    api_key=openai_api_key,
    base_url=openai_api_base,
)

SPEAKER_LABELS = {
    'SPEAKER_00': 'patient',
    'SPEAKER_01': 'doctor'
}


def seconds_to_hhmmss(seconds):
    td = datetime.timedelta(seconds=seconds)
    total_seconds = td.total_seconds()
    # Calculate hours, minutes, seconds, and milliseconds
    hours, remainder = divmod(total_seconds, 3600)
    minutes, remainder = divmod(remainder, 60)
    seconds, milliseconds = divmod(remainder, 1)
    # Convert to integers
    hours = int(hours)
    minutes = int(minutes)
    seconds = int(seconds)
    milliseconds = int(milliseconds * 1000)
    # Format the string
    formatted_time = f"{hours:02}:{minutes:02}:{seconds:02}.{milliseconds:03}"
    return formatted_time

def process_pyannote_output(file_path, adjustment_seconds=0):
    segments = []
    with open(file_path, 'r') as file:
        for line in file:
            parts = re.split(r'\s+', line.strip())
            speaker = parts[7]
            start_time = float(parts[3]) + adjustment_seconds # MAKE ADJUSTMENT SECONDS NEGATIVE TO TAKE OFF TIME
            duration = float(parts[4]) 
            end_time = start_time + duration
            segments.append((speaker, start_time, end_time, duration))
    return segments

def group_segments(segments, segment_seconds_threshold=60, segment_hard_limit=120, segment_break_seconds=1.5):
    grouped = []
    current_group = []
    current_speaker = None
    previous_end = 0

    for segment in segments:
        speaker, start, end, duration = segment
        if speaker == current_speaker:
            segment_start = current_group[-1][1]
            if (end - segment_start >= segment_seconds_threshold and start - previous_end >= segment_break_seconds) \
                or end - segment_start >= segment_hard_limit:
                if current_group:
                    grouped.append(current_group)
                current_group = [(speaker, start, end)]
            else:
                current_group[-1] = (current_speaker, current_group[-1][1], end)
        else:
            if current_group:
                grouped.append(current_group)
            current_group = [(speaker, start, end)]
            current_speaker = speaker
        previous_end = end

    if current_group:
        grouped.append(current_group)

    return grouped

def create_timestamps(segments):
    timestamps = []
    for segment in segments:
        speaker, start, end, duration = segment
        timestamps.append((speaker, start, end))
    return timestamps

def create_timestamps_grouped(grouped_segments):
    timestamps = []
    for group in grouped_segments:
        speaker = group[0][0]
        start = round(group[0][1], 3)
        end = round(group[-1][2], 3)
        timestamps.append((speaker, start, end))
    return timestamps

def get_smallest_duration(whisper_json):
    smallest_time = 100
    with open(whisper_json, 'r') as file:
        data = json.load(file)
        
        for segment in data['segments']:
            t = segment['end'] - segment['start']
            if t < smallest_time and t != 0:
                print(segment['end'], segment['start'])
                smallest_time = t
    return smallest_time

def calculate_softmax_probabilities(numbers, target):
    try:
        # Calculate distances from the target
        distances = np.array([abs(number - target) for number in numbers])
        
        # Convert distances to similarities (inverse of distance)
        similarities = 1 / (distances + 1e-10)  # Add a small value to avoid division by zero
        
        # Apply softmax to similarities
        exp_similarities = np.exp(similarities)
        probabilities = exp_similarities / np.sum(exp_similarities)
    except: 
        return []
    
    return probabilities

def get_overlaps(whisper_json, timestamps):
    overlaps = []
    with open(whisper_json, 'r') as file:
        data = json.load(file)
        for segment in data['segments']:
            if segment['text'] == '': 
                continue
            whisper_start, whisper_end = segment['start'], segment['end']
            element = {
                'text': segment['text'],
                'possible_speakers': {}
            }
            for timestamp in timestamps:
                speaker = SPEAKER_LABELS[timestamp[0]]
                start, end = timestamp[1], timestamp[2]
                
                if start < whisper_end and whisper_start < end:
                    if not speaker in element['possible_speakers']:
                        element['possible_speakers'][speaker] = end-start
                    else:
                        element['possible_speakers'][speaker] += end-start
            durations = []
            for possible_speaker in element['possible_speakers']:
                durations.append(element['possible_speakers'][possible_speaker])
            try: # FIX ME
                probabilities = calculate_softmax_probabilities(durations, whisper_end-whisper_start)
            except:
                probabilities = []
            for i, possible_speaker in enumerate(element['possible_speakers']):
                element['possible_speakers'][possible_speaker] = probabilities[i]
            overlaps.append(element)
    return overlaps
    
def get_language_model_guesses_old(labels, max_lines=60, llm_guess_weight=.5):
    diart_weight = 1-llm_guess_weight

    object_schema = {
        "$defs": {
            "speaker_type": {
                "enum": ["doctor", "patient"],
                "title": "Speaker",
                "type": "string"
            }
        },
        "properties": {
            "text": {"title": "Text", "type": "string"},
            "speaker" : {"$ref": "#/$defs/speaker_type"}
        },
        "required": ["text", "speaker"],
        "type": "Object"
    }

    for i in range(0, len(labels), max_lines):
        lines = ''
        for label in labels[i:i+max_lines]:
            lines += label['text'] + '\n\n'
        
        print(lines)
        completion = client.chat.completions.create(
            model=adapter_id,
            messages=[
                {"role": "system", "content": "There are two speakers in the given text. for each line determine who is speaking: text (the text being analyzed), and speaker (the most likely speaker, doctor or patient, for the given text)"},
                {"role": "user", "content": lines},
            ],
            max_tokens = 3000,
            temperature = 0.7,
            response_format={ 
                "type": "json_object",
                "schema": {
                    "type": "array",
                    "items": object_schema
                }
            }
        )
        print("Completion result:")
        print(completion)
        # try:
        guesses = json.loads(completion.choices[0].message.content)
        print(len(guesses))
        for j in range(i, i+max_lines):
            if j > len(labels)-1:
                break
            if guesses[j]['text'] == labels[j]['text']:
                if 'None' in labels[j]['possible_speakers'].keys():
                    labels[j]['possible_speakers'][guesses[j]['speaker']] = 1
                    del labels[j]['possible_speakers']['None']
                else:
                    for speaker in labels[j]['possible_speakers']:
                        labels[j]['possible_speakers'][speaker] *= diart_weight
                        if guesses[j]['speaker'] == speaker:
                            labels[j]['possible_speakers'][speaker] += llm_guess_weight
                    if guesses[j]['speaker'] not in labels[j]['possible_speakers']:
                        labels[j]['possible_speakers'][guesses[j]['speaker']] = llm_guess_weight
                        

                    
                
        # except Exception as e:
        #     print('Failed to get guesses from model')
        #     print(e)
        break


def get_language_model_guesses(language_model_guess_file, labels, speaker_roles, llm_guess_weight=.5):
    diart_weight = 1-llm_guess_weight
    lines = ''
    num_lines = 40 if 40 < len(labels) else len(labels)
    # for i in range(num_lines):
    #     lines += labels[i]['text'] + '\n'
    messages = []
    counter = 0

    GUESS_SCHEMA = {
        "type": "object",
        "properties": {
            "guess": {
                "type": "string",
                "enum": speaker_roles
            }
        },
        "required": ["guess"]
    }

    for label in labels:
        if llm_guess_weight > 0:
            if counter % num_lines == 0:
                messages = []
                lines = ''
                for i in range(num_lines):
                    lines += f"{i+1}: {labels[i]['text']}\n"
                messages.append({"role": "system", "content": f"There are {len(speaker_roles)} speakers in the given text. You will be given a line from the text and you must identify the most likely speaker of {json.dumps(speaker_roles)}. The full text you will be analyzing is:\n" + lines})
            print(label)
            messages.append({"role": "user", "content": f"{(counter%num_lines)+1}: {label['text']}"})
            completion = client.chat.completions.create(
                model=adapter_id,
                messages=messages,
                max_tokens = 25,
                temperature = 0.7,
                response_format = GUESS_SCHEMA,
            )
        # print("Completion result:")
        # print(completion)
        try:
            if llm_guess_weight > 0:
                guess = json.loads(completion.choices[0].message.content)['guess']
            else:
                guess = speaker_roles[0]
            with open(language_model_guess_file, 'a+') as file:
                file.write(f"{guess} {label['text']}\n")
            if 'None' in label['possible_speakers'].keys():
                label['possible_speakers'][guess] = round(1/len(speaker_roles), 2)
                del label['possible_speakers']['None']
            else:
                for speaker in label['possible_speakers']:
                    label['possible_speakers'][speaker] *= diart_weight
                    if guess == speaker:
                        label['possible_speakers'][speaker] += llm_guess_weight
                if guess not in label['possible_speakers']:
                    label['possible_speakers'][guess] = llm_guess_weight
            if llm_guess_weight > 0:              
                messages.append({"role": "model", "content": guess})

            print(label)      
                
        except Exception as e:
            print('Failed to get guesses from model')
            print(e)
        # break
        counter += 1

def merge_diart_and_llm(language_model_guess_file, labels, llm_guess_weight=.51):
    guesses = []
    diart_weight = 1-llm_guess_weight
    with open(language_model_guess_file, 'r') as file:
        for line in file:
            parts = line.split(maxsplit=1)
            if len(parts) == 2:
                first_word, rest_of_text = parts
                guesses.append((first_word, rest_of_text.strip()))
            elif len(parts) == 1:
                first_word = parts[0]
                guesses.append((first_word, ''))
    print(len(guesses), len(labels))
    i = 0
    for label in labels:
        guess = guesses[i][0]
        if (guesses[i][1].strip() != label['text'].strip()):
            print(guesses[i][1]),
            print(label['text'])
            # if len(label['text'].strip()) != 0:
            #     i += 1
            continue
        if 'None' in label['possible_speakers'].keys():
            label['possible_speakers'][guess] = 1
            del label['possible_speakers']['None']
        else:
            for speaker in label['possible_speakers']:
                label['possible_speakers'][speaker] *= diart_weight
                if guess == speaker:
                    label['possible_speakers'][speaker] += llm_guess_weight
            if guess not in label['possible_speakers']:
                label['possible_speakers'][guess] = llm_guess_weight
        i += 1
        




def get_transcription_from_labels(transcript_file, labels):
    with open(transcript_file, 'w+', encoding='utf-8') as file:
        current_speaker = ''
        for label in labels:
            speaker = 'None'
            if label['possible_speakers'] != {}:
                speaker = max(label['possible_speakers'], key=lambda k: label['possible_speakers'][k])
            # if current_speaker != speaker:
            #     current_speaker = speaker
                # file.write(current_speaker + '\n')
            percentage = f'{round(round(label["possible_speakers"][speaker], 4)*100, 2)}%' if speaker != 'None' else ''
            file.write(f'{speaker} {percentage}: {label["text"]}\n')


def merge_transcription_and_diarization(whisper_json, merged_json_file, labels):
    transcriptions = []
    with open(whisper_json, 'r') as json_file:
        transcriptions = json.load(json_file)
    with open(merged_json_file, 'w+', encoding='utf-8') as file:
        # file.write('{\n')
        # file.write(f'"text": {transcriptions["text"]},\n')
        # file.write('"')
        transcription_index = 0
        for i, label in enumerate(labels):
            # print(label)
            # break
            speaker = 'None'
            if label['possible_speakers'] != {}:
                speaker = max(label['possible_speakers'], key=lambda k: label['possible_speakers'][k])
            while transcriptions['segments'][transcription_index]['text'] != label['text']:
                transcription_index += 1
            confidence = 0
            if speaker in label['possible_speakers']:
                confidence = label['possible_speakers'][speaker]
            if not confidence:
                confidence = 0
            transcriptions['segments'][transcription_index]['speaker'] = {
                'speaker': speaker,
                'probability': confidence
            }
        file.write(json.dumps(transcriptions))
    


def main(rttm_path, whisper_json, llm_guess_file, llm_guess_weight, transcript_file, merged_file, speaker_roles):
    output_dir = 'output'
    adjustment_seconds=0

    segments = process_pyannote_output(rttm_path, adjustment_seconds)
    # segments = adjust_segments(segments)

    # INDIVIDUAL
    # timestamps = create_timestamps(segments)
    # GROUPED
    grouped_segments = group_segments(segments, 2000, 2000)
    timestamps = create_timestamps_grouped(grouped_segments)

    # for timestamp in timestamps:
    #     print(timestamp) 

    possible_labels = get_overlaps(whisper_json, timestamps)
    # clear current contents and make new file if necessary
    with open(llm_guess_file, 'w+'):
        pass
    get_language_model_guesses(llm_guess_file, possible_labels, speaker_roles, llm_guess_weight)
    print(json.dumps(possible_labels, indent=2))

    print('Merging diarization and LLM guesses...')
    merge_diart_and_llm(llm_guess_file, possible_labels, llm_guess_weight)
    get_transcription_from_labels(transcript_file, possible_labels)

    print('Merging transcription and diarization...')
    merge_transcription_and_diarization(whisper_json, merged_file, possible_labels)


if __name__ == '__main__':

    if (len(sys.argv) != 7):
        print(f"usage: python {sys.argv[0]} <rttm file> <json file> <txt file> <float between 0 and 1> <output transcript file (txt)> <output merged file(json)>")
        sys.exit(1)
    rttm_path = sys.argv[1]
    whisper_json = sys.argv[2]
    llm_guess_file = sys.argv[3]
    llm_guess_weight = float(sys.argv[4])
    transcript_file = sys.argv[5]
    merged_file = sys.argv[6]
    main(rttm_path, whisper_json, llm_guess_file, llm_guess_weight, transcript_file, merged_file)
    
