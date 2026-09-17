import argparse
import json
import os
import sys
import re
import shutil
import hashlib
from glob import glob
from os.path import isfile, join
from openai import OpenAI
import subprocess
import time
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
import asyncio
import traceback
import botocore # type: ignore

# --- LLM Client Setup ---
config = configparser.ConfigParser()
config.read('/workspace/config.ini')
base_url = config.get('LLM', 'openai_api_base')
api_key = config.get('LLM', 'openai_api_key')
client = OpenAI(api_key=api_key, base_url=base_url)


def safe_loads_json(json_string):
    # Step 1: Replace single-quoted strings with double-quoted strings
    json_string = re.sub(r"'([^'\\]*(?:\\.[^'\\]*)*)'", r'"\1"', json_string)

    # Step 2: Replace inner double quotes with single quotes inside string values
    pattern = r'("([^"\\]*(?:\\.[^"\\]*)*)")'  # Matches string values in JSON

    def replacer(match):
        value = match.group(2)  # Extract string content
        # Replace inner double quotes with single quotes
        replaced_value = value.replace('"', "'")
        # Return the modified value with double quotes
        return f'"{replaced_value}"'

    # Apply inner quote replacement
    modified_string = re.sub(pattern, replacer, json_string)

    # Step 3: Parse the modified JSON string into a Python object
    try:
        return json.loads(modified_string)
    except json.JSONDecodeError as e:
        print(f"Error decoding JSON: {e}")
        return None


def run_query_lines(transcript, num_lines_to_process, system_prompt, user_prompt, max_tokens=2000, temperature=0.8):
    llm_outputs = []
    for i in range(0, len(transcript), num_lines_to_process):
        group_of_lines = transcript[i:i+num_lines_to_process]
        lines_text = "".join(group_of_lines)
        messages = [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": f"{user_prompt}. Here are the lines of the transcript: {lines_text}."},
        ]
        response = client.chat.completions.create(
            model="meta-llama/Llama-3.2-90B-Vision-Instruct",
            messages=messages,
            max_tokens=max_tokens,
            temperature=temperature,
            stream=True,
        )
        summary_chunks = []
        for chunk in response:
            delta = getattr(chunk.choices[0], "delta", None)
            if delta and hasattr(delta, "content") and delta.content:
                summary_chunks.append(delta.content)
        out = "".join(summary_chunks)
        if out and '</think>' in out:
            out = out.split('</think>', 1)[-1].strip()
        llm_outputs.append(out)
        print(f'OUT OUT OUT: \n\n\n\n\n{out}\n\n\n\n\n')
    return llm_outputs


def process_file(file_path, system_prompt, user_prompt, max_tokens=2000, temperature=0.8):
    with open(file_path, 'r', encoding='utf-8') as file:
        transcript = file.read()
    conversation_history = []
    messages = [
        {"role": "user", "content": f"{user_prompt}. Here is the transcript: {transcript}."},
    ]
    response = client.chat.completions.create(
        model="meta-llama/Llama-3.2-90B-Vision-Instruct",
        messages=conversation_history + messages,
        max_tokens=max_tokens,
        temperature=temperature,
        stream=True,
    )
    summary_chunks = []
    for chunk in response:
        delta = getattr(chunk.choices[0], "delta", None)
        if delta and hasattr(delta, "content") and delta.content:
            summary_chunks.append(delta.content)
            print(delta.content, end="", flush=True)
    summary = "".join(summary_chunks)
    if summary and '</think>' in summary:
        summary = summary.split('</think>', 1)[-1].strip()
    print(f'RESPONSE:\n{summary}\n')
    return summary


def riskalyze(transcript_path, outputs_dir, system_prompt, user_prompt, max_tokens=10000, temperature=0.8):
    new_filename = os.path.splitext(os.path.basename(transcript_path))[0] + '_riskalyze.txt'
    output_path = outputs_dir + '/' + new_filename
    final_summary = process_file(transcript_path, system_prompt, user_prompt, max_tokens, temperature)
    print(f'Writing results to {output_path}')
    with open(output_path, 'w+', encoding='utf-8') as result_file:
        result_file.write(final_summary)
    return new_filename, 0


callback_url = config.get('API Server', 'callback_url')
updates_api_key = config.get('API Server', 'updates_api_key')
cat_talk_callback_url = config.get('API Server', 'callback_url_2')


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


def chunk_lines(file_path, chunk_size=64):
    with open(file_path, 'r', encoding='utf-8') as file:
        lines = file.readlines()
    chunks = [lines[i:i + chunk_size] for i in range(0, len(lines), chunk_size)]
    return chunks


if __name__ == '__main__':
    try:
        print('Starting Train Script')
        parser = argparse.ArgumentParser(description='LLM Factory Agent')

        # General arguments
        parser.add_argument('--project_name', type=str, default='SpeakEZ', help='name of clearml project')
        parser.add_argument('--task_name', type=str, default='riskalyzer_task', help='name of clearml task')
        parser.add_argument('--dataset_path', type=str, default='/workspace/custom_data', help='location of dataset') # this is necessary but idk what it does
        parser.add_argument('--project_id', type=str, default='TEMPLATE_DATASET_DO_NOT_REMOVE', help='the id of the project and the name of the dataset in clearml')
        parser.add_argument('--collection_id', type=str, default='TEMPLATE_COLLECTION_DO_NOT_REMOVE', help='collection id')

        # Dataset parameters
        parser.add_argument('--dataset_project', type=str, default='SpeakEZ_Datasets', help='clearml project location of dataset')
        # parser.add_argument('--dataset_name', type=str, default='oral_history', help='clearml dataset name')
        parser.add_argument('--file_id', type=str, default='fitnessgram', help='clearml dataset file id')
        # parser.add_argument('--outputs_dir', type=str, default='./outputs', help='directory of outputs of each verbatimizer step')

        #parser.add_argument('--clearml_cache', type=str, default=os.path.join(user_home,'.clearml/cache'), help='location of dataset')
        #parser.add_argument('--clearml_cache', type=str, default='/app/cache', help='location of dataset')
        parser.add_argument('--job_id', type=str, help='id of job')


        args = parser.parse_args()

        system_prompt = "You are a helpful assistant."
        user_prompt = "Extract sensitive content from this portion of a transcript. Sensitive content only includes racism, sexual content, defamation, libel, slander, and other types of content related to OTHER people. The person being interviewed is allowed to share personal information, and that content should not be flagged. List the line number, why the content is considered sensitive, and the excerpt of sensitive content. Do not say if part of the conversation appears to be missing, and do not output anything extra. Look at your conversation history and add on to the previous report if you think that the part of the transcript you are looking at is a continuation of that. Only output information in the required format. Say nothing else."

        clearml_dataset_name = f'{args.project_id}_{args.file_id}_outputs'
        # pull the file to verbatimize from s3 and cache local to DGX
        clearml_dataset = Dataset.get(dataset_name=clearml_dataset_name, dataset_project=args.dataset_project)
        dataset_cache_path = clearml_dataset.get_local_copy()

        print('Starting ClearML Task')
        task = Task.init(project_name=args.project_name, task_name=args.task_name)
        task_id = str(task.current_task().id)
        print('Task_id:', task_id)


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

        #api_task = asyncio.create_task(launch_api_server())
        #print('Waiting for vLLM to start...')

        # Other tasks that can run without waiting for `vllm` to stop
        #while True:
            # Simulate periodic checks
        #    if await is_server_running():
        #        print('vLLM has started')
        #        break
        #    await asyncio.sleep(15)



        # local openai client for querying model
        #client = OpenAI( # local openai client for querying model
        #    base_url="http://localhost:8000/v1",
        #    api_key="abc123"
        #)

        '''
        if "PYTHONPATH" in os.environ:
            print('PYTHONPATH 1:', os.environ["PYTHONPATH"])
            #del os.environ['PYTHONPATH']
        if "PYTHONPATH" in os.environ:
            print('PYTHONPATH 2:', os.environ["PYTHONPATH"])
        '''
        custom_task_data_path = os.path.join(args.dataset_path, task_id)


        outputs_local_dir = os.environ.get('OUTPUT_DIR')
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
            if not os.path.exists(outputs_local_dir):
                os.mkdir(outputs_local_dir)
            print([args.file_id, outputs_local_dir])
            print('ls:')
            print(os.listdir(dataset_cache_path), end='\n\n')
            temp = next((f for f in  os.listdir(dataset_cache_path) if f.endswith('.transcript')), None)
            transcript_file_path = f'{dataset_cache_path}/{temp}'
            #transcript_file_name = next((f for f in transcript_file_path if f.endswith('.rttm')), None)
            #transcript_file_path = dataset_cache_path + '/' + transcript_file_name
            #try:
            #    transcript_file_name = next((f for f in os.listdir(dataset_cache_path) if f.endswith('.rttm')), None)
            #    transcript_file_path = dataset_cache_path + '/' + transcript_file_name
            #except:
            #    print('so so weird')
            print(f'dataset_cache_path: {dataset_cache_path}')
            print(f'args.file_id: {args.file_id}')
            print(f'transcript_file_path: {transcript_file_path}')
            print(f'args.collection_id: {args.collection_id}')

            json_file_path, riskalyze_rc = riskalyze(transcript_file_path, outputs_local_dir, system_prompt, user_prompt)
            if riskalyze_rc != 0:
                raise ValueError(f"Training failed with riskalyzer return code {riskalyze_rc}")

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

                print('Uploading risk analysis: ', outputs_local_dir)
                dataset_files = [f for f in os.listdir(outputs_local_dir) if isfile(join(outputs_local_dir, f))]
                for dataset_file in dataset_files:
                    print(f'dataset_file: {dataset_file}')
                    local_dataset_path = os.path.join(outputs_local_dir, dataset_file)
                    print(f'local_dataset_path: {local_dataset_path}')
                    remote_dataset_path = args.project_id + '/' + args.collection_id + '/' + dataset_file
                    print(f'remote_dataset_path: {remote_dataset_path}')
                    try:
                            response = transfer.upload_file(local_dataset_path, s3_bucket, remote_dataset_path)
                    except:
                        with botocore.exceptions.ClientError as e:
                            print(e.response['Error']['Message'])
                            print(e.response['Error'])
                    os.remove(local_dataset_path)
                    print(f'response: {response}')


        else:
            raise Exception('Dataset preparation failed!')


        #remove temp
        #shutil.rmtree(tmp_custom_task_data_path)
        riskalyze_update_url = callback_url + '/updates/riskalyzer'
        update_status(args.file_id, 'riskalyzer', 'complete', 'Ready for further analysis', riskalyze_update_url)

        riskalyze_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'riskalyzer', 'complete', 'Ready for analysis', riskalyze_update_url)

        metrics_url = cat_talk_callback_url + '/metrics/update'

        #print('Finished Training, cleaning files')
        #clean_paths = [custom_task_data_path, dataset_cache_path]
        #for path in clean_paths:
        #    if path is not None:
        #        if os.path.exists(path):
        #            print('Removing path:', path)
        #            shutil.rmtree(path)
    #for name, value in os.environ.items():
    #    print("{0}: {1}".format(name, value))
    except Exception as e: # on job failure
        print(e)
        traceback.print_exc()
        riskalyze_update_url = callback_url + '/updates/riskalyzer'
        update_status(args.file_id, 'riskalyzer', 'failed', '', riskalyze_update_url)

        riskalyze_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'riskalyzer', 'failed', '', riskalyze_update_url)

    task.close()