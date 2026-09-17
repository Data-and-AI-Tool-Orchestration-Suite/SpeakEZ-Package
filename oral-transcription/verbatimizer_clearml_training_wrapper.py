import argparse
import asyncio
import json
import os
import re
import shutil
import hashlib
from glob import glob
from os.path import isfile, join

import pandas as pd
from clearml import Task, Dataset
from clearml import Logger
import yaml
from pathlib import Path

from clearml.utilities import pyhocon
import boto3 # type: ignore
from s3transfer import TransferConfig, S3Transfer # type: ignore

import concurrent.futures

import configparser
import requests

from pydub import AudioSegment


def convert_to_mp3(input_file, output_file):
    audio = AudioSegment.from_file(input_file)
    audio.export(output_file, format='mp3')
    print(f'converted {input_file} to mp3')


####################### diarize.py ############################################################################################
# import torch
import sys
import os
import time

# instantiate the pipeline
from pyannote.audio import Pipeline
import wave
import contextlib
import numpy as np


def diarize(audio_file):

    pipeline_config = {
        'sad': {
            'model_duration': 2.0
        },
        'scd': {
            'min_duration_on': 0.1,  # Speaker change detection minimum duration on
            'min_duration_off': 0.1,  # Speaker change detection minimum duration off
            'min_duration_overlap': 0.0,  # Minimum overlap duration
            'min_duration_coverage': 0.7  # Minimum coverage duration
        },
        'overlap_detection': True  # Enable overlap detection
    }
    #pipeline.to(torch.device("cuda"))


    pipeline = Pipeline.from_pretrained(
        'pyannote/speaker-diarization-3.1',
        use_auth_token=os.environ.get('HF_TOKEN', ''),  # HuggingFace token with access to pyannote models (set in environment)
        # **pipeline_config
    )
    device = "cuda" if torch.cuda.is_available() else "cpu"
    print(f'diarize device: {device}')
    pipeline.to(torch.device(device))
    # print(pipeline.__dict__.keys())
    print(pipeline._parameters.__dict__)

    # Define audio file path
    # audio_file = "meow.wav"

    # Preprocess audio if needed (example: normalize audio)
    def normalize_audio(audio_path):
        with wave.open(audio_path, 'rb') as wf:
            params = wf.getparams()
            frames = wf.readframes(params.nframes)
            audio_data = np.frombuffer(frames, dtype=np.int16)

            # Normalize audio data
            max_val = np.max(np.abs(audio_data))
            audio_data = (audio_data / max_val * 32767).astype(np.int16)

            # Write normalized audio back
            with wave.open(audio_path, 'wb') as wf_normalized:
                wf_normalized.setparams(params)
                wf_normalized.writeframes(audio_data.tobytes())

    # normalize_audio(audio_file)

    pipeline._parameters = pipeline_config
    pipeline.instantiate(pipeline_config)

    # run the pipeline on an audio file
    diarization = pipeline(audio_file, num_speakers=2)

    # Post-process diarization result
    # Merge short segments of the same speaker, smooth boundaries, etc.
    from pyannote.core import Segment

    # Example of merging short segments
    def merge_short_segments(diarization, min_duration=0.5):
        new_diarization = diarization.empty()
        for turn, _, speaker in diarization.itertracks(yield_label=True):
            if turn.duration < min_duration:
                if len(new_diarization) > 0 and new_diarization[-1].track.label == speaker:
                    new_diarization[-1].track.segment = Segment(new_diarization[-1].track.segment.start, turn.end)
                else:
                    new_diarization[turn] = speaker
            else:
                new_diarization[turn] = speaker
        return new_diarization

    # diarization = merge_short_segments(diarization)

    # dump the diarization output to disk using RTTM format
    rttm_file_path = f'output/{os.path.splitext(os.path.basename(audio_file))[0]}.rttm'
    with open(rttm_file_path, 'w+') as rttm:
        diarization.write_rttm(rttm)

    return 0




# if __name__ == '__main__':
#     print(f'argv: {sys.argv}')
#     if len(sys.argv) != 2:
#         print(f'Usage: python {sys.argv[0]} <filepath>')
#         sys.exit(1)

#     # Audio file path
#     audio_file = sys.argv[1]

#     diarize(audio_file)

####################################################################################################################################################################################
############################################### transcribe.py ######################################################################################################################
import json
from typing import Union, Tuple
# import diart.models as m
import whisper
# from diart import SpeakerDiarization, SpeakerDiarizationConfig
# from diart.inference import StreamingInference
# from diart.progress import TQDMProgressBar
# from diart.sinks import RTTMWriter
# from diart.sources import FileAudioSource
# from pyannote.core import Annotation
from pprint import pprint
import time


# def _extract_prediction(value: Union[Tuple, Annotation]) -> Annotation:
#     if isinstance(value, tuple):
#         return value[0]
#     if isinstance(value, Annotation):
#         return value
#     msg = f"Expected tuple or Annotation, but got {type(value)}"
#     raise ValueError(msg)

import sys
import os

def timestamp_to_float(minutes, seconds, ms=0):
    return (minutes*60)+seconds+(ms/1000)

# audio_file = './meow.wav'

# clip_start = timestamp_to_float(21, 30)
# clip_end   = timestamp_to_float(22, 0)
# clip_timestamps = f'{clip_start},{clip_end}'

import torch
device = "cuda" if torch.cuda.is_available() else "cpu"
print(f'transcribe device: {device}')
def transcribe(audio_file):
    # transcribe now
    print("Transcribing...")
    model = whisper.load_model('large', device=device)
    result = model.transcribe(
        audio_file,
        verbose=True,
        word_timestamps=True,
        # clip_timestamps=clip_timestamps
    )
    print(result)
    json_file_path = f'output/{os.path.splitext(os.path.basename(audio_file))[0]}.json'
    with open(json_file_path, 'w+') as file:
        file.write(json.dumps(result))

    # transcribe now
    print("Transcribing...")
    model = whisper.load_model('large')
    result = model.transcribe(
        audio_file,
        verbose=True,
        word_timestamps=True,
        # clip_timestamps=clip_timestamps
    )
    print(result)
    json_file_path = f'output/{os.path.splitext(os.path.basename(audio_file))[0]}.json'
    with open(json_file_path, 'w+') as file:
        file.write(json.dumps(result))

    txt_file_path = f'output/{os.path.splitext(os.path.basename(audio_file))[0]}.txt'
    for segment in result['segments']:
        with(open(txt_file_path, 'a', encoding="utf-8")) as f:
            f.writelines(f'{segment["id"]}\t{"%.3f"%(segment["start"])}\t{"%.3f"%(segment["end"])}\t{segment["text"]}\n')

    return 0



# if __name__ == '__main__':
#     if len(sys.argv) != 2:
#         print(f'Usage: python {sys.argv[0]} <filepath>')
#         sys.exit(1)

#     # Audio file path
#     audio_file = sys.argv[1]

#     transcribe(audio_file)

########################################################################################################################################################################################################



config = configparser.ConfigParser()
config.read('config.ini')
callback_url = config.get('API Server', 'callback_url')
api_key = config.get('API Server', 'api_key')
audio_bucket = config.get('S3', 'audio_bucket')

step_count = 1

user_home = str(Path.home())

def extract_string_between_curly_braces(text):
    match = re.search(r'\{(.*?)\}', text)
    if match:
        return match.group(1)
    else:
        return None

async def _read_stream(stream, cb):
    while True:
        line = await stream.readline()
        if line:
            cb(line)
        else:
            break

async def _stream_subprocess(cmd, stdout_cb, stderr_cb):
    process = await asyncio.create_subprocess_exec(*cmd,
            limit=1024 * 1024 * 10,  # 10M buffer
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE)

    await asyncio.gather(
        _read_stream(process.stdout, stdout_cb),
        _read_stream(process.stderr, stderr_cb)
    )
    return await process.wait()

# def execute(cmd, stdout_cb, stderr_cb):
#     loop = asyncio.get_event_loop()
#     rc = loop.run_until_complete(
#         _stream_subprocess(
#             cmd,
#             stdout_cb,
#             stderr_cb,
#     ))
#     loop.close()
#     return rc

async def execute(cmd, stdout_cb, stderr_cb):
    rc = await _stream_subprocess(
        cmd,
        stdout_cb,
        stderr_cb,
    )
    return rc

def upload_training_stats(training_stats):
    global step_count  # declare a to be a global
    print('upload_training_stats:', training_stats)
    Logger.current_logger().report_scalar("STEP_EPOCH", "step_epoch", iteration=step_count, value=training_stats['epoch'])
    Logger.current_logger().report_scalar("LOSS", "loss", iteration=step_count, value=training_stats['loss'])
    Logger.current_logger().report_scalar("LR", "lr", iteration=step_count, value=training_stats['learning_rate'])
    step_count += 1

def update_training_metrics(metric_key, metric_value):
    print('update_training_metrics: UPLOAD TRAINING METRIC:', 'metric_key:', metric_key, 'metric_value:', metric_value)
    Logger.current_logger().report_single_value(metric_key, metric_value)

def stdout_callback(x):
    x = x.decode("utf-8")
    print('stdout', x, end="")

def stderror_callback(x):
    x = x.decode("utf-8")
    print('stderror', x, end="")

def set_env():
    env_keys = []
    env_values = []

    for arg in vars(args):
        env_key = arg.upper()
        print(f'arg: {arg}')
        print(f'env_key: {env_key}')
        env_value = str(getattr(args, arg))
        print(f'env_value: {env_value}')
        os.environ[env_key] = env_value
        env_keys.append(env_key)
        env_values.append(env_value)

    data = {'env_keys': env_keys, 'env_values': env_values}
    df = pd.DataFrame.from_dict(data)
    Logger.current_logger().report_table(title='ENV Table', series='ENVs', iteration=0, table_plot=df)

def get_file_sha1(dataset_path):
    BUF_SIZE = 65536  # lets read stuff in 64kb chunks!
    sha1 = hashlib.sha1()
    with open(dataset_path, 'rb') as f:
        while True:
            data = f.read(BUF_SIZE)
            if not data:
                break
            sha1.update(data)
    return sha1.hexdigest()

def validate_dataset():
    dataset_sha1 = None
    f = open('/workspace/custom_data/dataset_info.json', "r")
    dataset_info = json.loads(f.read())
    if args.dataset in dataset_info:
        dataset_path = os.path.join('data', dataset_info[args.dataset]['file_name'])
        dataset_sha1 = get_file_sha1(dataset_path)
    return dataset_sha1


def update_status(file_id, job, status, message, update_url):

    headers = {
        'Authorization': f'Bearer {api_key}',
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
        print(response)
        response.raise_for_status()  # Raise an error for bad responses
    except requests.RequestException as e:
        print(f"Error updating status: {e}")

'''
Process for Verbatimizing
'''
async def verbatimize(file_path, outputs_dir):
    # print(f'outputs_dir: {outputs_dir}')
    # diarize_command = ['python3', 'diarize.py', file_path]
    # def diarize_stdout(output):
    #     print(f'Diarization OUT: {output}')
    # def diarize_stderr(output):
    #     print(f'Diarization ERR: {output}')
    # transcribe_command = ['python3', 'transcribe.py', file_path]
    # def transcribe_stdout(output):
    #     print(f'Transcription OUT: {output}')
    # def transcribe_stderr(output):
    #     print(f'Transcription ERR: {output}')
    # diarize_rc, transcribe_rc = await asyncio.gather(
    #     execute(diarize_command, diarize_stdout, diarize_stderr),
    #     execute(transcribe_command, transcribe_stdout, transcribe_stderr)
    # )

    # if diarize_rc != 0:
    #     print('Diarization Failed')
    #     # return diarize_rc
    # else:
    #     print('Diarization Successful')
    # if transcribe_rc != 0:
    #     print('Transcription Failed')
    #     # return transcribe_rc
    # else:
    #     print('Transcription Successful')


    with concurrent.futures.ThreadPoolExecutor() as executor:
        diarize_future = executor.submit(diarize, file_path)
        transcribe_future = executor.submit(transcribe, file_path)

        # Wait for both functions to complete and get their results
        diarize_rc = diarize_future.result()
        transcribe_rc = transcribe_future.result()

    if diarize_rc != 0:
        print('Diarization Failed')
        # return diarize_rc
    else:
        print('Diarization Successful')
    if transcribe_rc != 0:
        print('Transcription Failed')
        # return transcribe_rc
    else:
        print('Transcription Successful')

    diarize_file_path = f'{outputs_dir}/{os.path.splitext(os.path.basename(file_path))[0]}.rttm'
    json_file_path = f'{outputs_dir}/{os.path.splitext(os.path.basename(file_path))[0]}.json'
    txt_file_path = f'{outputs_dir}/{os.path.splitext(os.path.basename(file_path))[0]}.txt'

    return diarize_rc, transcribe_rc, diarize_file_path, json_file_path, txt_file_path

    # merge_command = f'python3 merge.py "{diarize_file_path}" "{json_file_path}" "{txt_file_path}"'
    # def merge_stdout(output):
    #     print(f'Merging OUT: {output}')
    # def merge_stderr(output):
    #     print(f'Merging ERR: {output}')
    # merge_rc = await asyncio.gather(
    #     execute(merge_command, merge_stdout, merge_stderr),
    # )

    # if merge_rc != 0:
    #     print('Merging Failed')
    # else:
    #     print('Merging Successful')

    # filename_no_ext, _ = os.path.splitext(json_file_path)
    # transcript_file = f'{outputs_dir}/{filename_no_ext}_full_transcript_diarized.txt'
    # merged_file = f'{outputs_dir}/{filename_no_ext}_combo_merge.json'
    # return merge_rc, transcript_file, merged_file



if __name__ == '__main__':

    print('Starting Train Script')
    parser = argparse.ArgumentParser(description='LLM Factory Agent')

    # General arguments
    parser.add_argument('--project_name', type=str, default='SpeakEZ', help='name of clearml project')
    parser.add_argument('--task_name', type=str, default='verbatimizer_task', help='name of clearml task')
    parser.add_argument('--dataset_path', type=str, default='/workspace/custom_data', help='location of dataset') # this is necessary but idk what it does
    parser.add_argument('--project_id', type=str, default='TEMPLATE_DATASET_DO_NOT_REMOVE', help='the id of the project and the name of the dataset in clearml')
    parser.add_argument('--collection_id', type=str, default='TEMPLATE_COLLECTION_DO_NOT_REMOVE', help='collection id')
    parser.add_argument('--file_extension', type=str, default='.mp3', help='extension of the audiofile')

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

    clearml_dataset_name = f'{args.project_id}_{args.file_id}'
    # pull the file to verbatimize from s3 and cache local to DGX
    clearml_dataset = Dataset.get(dataset_name=clearml_dataset_name, dataset_project=args.dataset_project)
    dataset_cache_path = clearml_dataset.get_local_copy()

    '''
    tmp_task_id = str(uuid.uuid4())
    tmp_custom_task_data_path = os.path.join('/app/cache', tmp_task_id)
    os.mkdir(tmp_custom_task_data_path)

    os.environ["HOME"] = tmp_custom_task_data_path
    os.environ["OLDPW"] = tmp_custom_task_data_path

    os.environ["CLEARML_VENVS_BUILDS"] = os.path.join(tmp_custom_task_data_path, '.clearml','venvs-builds')
    os.environ["CLEARML_VCS_CACHE"] = os.path.join(tmp_custom_task_data_path, '.clearml', 'vcs-cache')
    os.environ["CLEARML_PIP_CACHE"] = os.path.join(tmp_custom_task_data_path, '.clearml', 'pip-download-cache')
    os.environ["CLEARML_DOCKER_PIP_CACHE"] = os.path.join(tmp_custom_task_data_path, '.clearml', 'pip-cache')
    os.environ["CLEARML_APT_CACHE"] = os.path.join(tmp_custom_task_data_path, '.clearml', 'apt-cache')

    if "PYTHONPATH" in os.environ:
        print('PYTHONPATH 0:', os.environ["PYTHONPATH"])

    print('Setting HOME as:', os.environ["HOME"])
    #os.environ["CLEARML_LOG_ENVIRONMENT"] = tmp_custom_task_data_path
    os.environ["CLEARML_TASK_NO_REUSE"] = '1'
    os.environ["CLEARML_LOG_LEVEL"] = 'INFO'
    '''

    print('ENVS:')
    for name, value in os.environ.items():
        print("{0}: {1}".format(name, value))

    control_node = False

    if "PMIX_RANK" in os.environ:
        if os.environ["PMIX_RANK"] == '0':
            control_node = True
    else:
        control_node = True

    print('Starting ClearML Task')
    task = Task.init(project_name=args.project_name, task_name=args.task_name)
    task_id = str(task.current_task().id)
    print('Task_id:', task_id)

    '''
    if "PYTHONPATH" in os.environ:
        print('PYTHONPATH 1:', os.environ["PYTHONPATH"])
        #del os.environ['PYTHONPATH']
    if "PYTHONPATH" in os.environ:
        print('PYTHONPATH 2:', os.environ["PYTHONPATH"])
    '''
    custom_task_data_path = os.path.join(args.dataset_path, task_id)


    outputs_local_dir = 'output'
    outputs_s3_bucket = 'output'


    if dataset_cache_path is not None:
        print('Dataset is prepared successfully. Starting training...')

        #allow script to cleanup on failure
        os.environ["CLEARML_CUSTOM_TASK_DATA_PATH"] = custom_task_data_path

        # Setting environment variables
        set_env()

        # Command to run for training
        # DO VERBATIMIZING
        # command = []
        # rc = execute(command, stdout_callback, stderror_callback)
        print([args.file_id, outputs_local_dir])
        print('ls:')
        print(os.listdir(dataset_cache_path), end='\n\n')
        audio_file_path = f'{dataset_cache_path}/{os.listdir(dataset_cache_path)[0]}'
        audio_file_path = audio_file_path + '/' + os.listdir(audio_file_path)[0]
        try:
            audio_file_path = audio_file_path + '/' + os.listdir(audio_file_path)[0]
        except:
            print('so so weird')
        print(f'dataset_cache_path: {dataset_cache_path}')
        print(f'args.file_id: {args.file_id}')
        print(f'args.file_extension: {args.file_extension}')
        print(f'audio_file_path: {audio_file_path}')
        print(f'args.collection_id: {args.collection_id}')
        
        # attempt to convert file to mp3
        try:
            convert_to_mp3(audio_file_path, audio_file_path)
        except Exception as e:
            print(e)

        diarize_rc, transcribe_rc, diarize_file_path, json_file_path, txt_file_path = asyncio.run(verbatimize(audio_file_path, outputs_local_dir))
        if diarize_rc != 0 and transcribe_rc != 0:
            raise ValueError(f"Training failed with diarize return code {diarize_rc} and transcribe return code {transcribe_rc}")

        if (args.job_id is not None) and (control_node):

            # remove checkpoints

            Logger.current_logger().report_text("Uploading transcriptions.", print_console=True)

            s3_bucket = 'output'
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
            dataset = Dataset.create(
                dataset_name=output_clearml_dataset_name, dataset_project=args.dataset_project
            )

            for dataset_file in dataset_files:
                print(f'dataset_file: {dataset_file}')
                local_dataset_path = os.path.join(outputs_local_dir, dataset_file)
                print(f'local_dataset_path: {local_dataset_path}')
                remote_dataset_path = args.project_id + '/' + args.collection_id + '/' + dataset_file
                print(f'remote_dataset_path: {remote_dataset_path}/{remote_dataset_path}')
                clearml_source_urls.append(f's3://{s3_bucket}/{remote_dataset_path}')
                clearml_dataset_paths.append('')
                try:
                        response = transfer.upload_file(local_dataset_path, s3_bucket, remote_dataset_path)
                except:
                    with botocore.exceptions.ClientError as e:
                        print(e.response['Error']['Message'])
                        print(e.response['Error'])
                os.remove(local_dataset_path)
                print(f'response: {response}')
            clearml_dataset.add_external_files(source_url = clearml_source_urls, dataset_path = clearml_dataset_paths, verbose=True)

            # Delete the file after processing
            if not args.noDelete:
                try:
                    response = s3_client.delete_object(Bucket=audio_bucket, Key=remote_dataset_path)
                    print(f"Deleted file: {remote_dataset_path} from bucket: {s3_bucket}")
                except Exception as e:
                    print(f"Error deleting file {remote_dataset_path}: {e}")


    else:
        raise Exception('Dataset preparation failed!')

    task.close()

    #remove temp
    #shutil.rmtree(tmp_custom_task_data_path)
    if control_node:
        verbatimize_update_url = callback_url + '/updates/verbatimizer'
        update_status(args.file_id, 'verbatimizer', 'Complete', 'Ready to merge', verbatimize_update_url)

        print('Finished Training, cleaning files')
        clean_paths = [custom_task_data_path, dataset_cache_path]
        for path in clean_paths:
            if path is not None:
                if os.path.exists(path):
                    print('Removing path:', path)
                    shutil.rmtree(path)

    #for name, value in os.environ.items():
    #    print("{0}: {1}".format(name, value))
