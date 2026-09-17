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
import logging

# --- LLM Client Setup ---
config = configparser.ConfigParser()
config.read('/workspace/config.ini')
base_url = config.get('LLM', 'openai_api_base')
api_key = config.get('LLM', 'openai_api_key')
client = OpenAI(api_key=api_key, base_url=base_url)

# --- Constants ---
DEFAULT_LARGE_CONTEXT_MODEL = config.get('Model', 'large_model')
DEFAULT_TOOL_CAPABLE_MODEL = config.get('Model', 'large_model')


MAX_LLM_RETRIES = 3
RETRY_DELAY_SECONDS = 5

# --- RISK ANALYSIS PROMPTS (Used as defaults for argparse) ---
RISK_SYSTEM_PROMPT = """
You are an archival risk and sensitivity assessment system designed to support informed accessioning and responsible stewardship in oral history archives.

Your task is to conduct an overly sensitive, comprehensive review of the provided transcript segment. Identify any content that could pose ethical, legal, reputational, or personal risk if made publicly accessible.
ASSUME A PRECAUTIONARY STANDARD. It is better to flag too much than to miss anything potentially sensitive.

CATEGORIES TO FLAG:
1. PII (Personal Identifiable Information): Addresses, phone numbers, SSNs, DOBs, info about minors.
2. Health/Medical: Physical/mental illness, disabilities, addiction, sexual health.
3. Sexual Content: Sexual activity, orientation, abuse, assault, explicit descriptions.
4. Illegal Activity: Crimes, drug use, fraud, violence, immigration status.
5. Accusations: Misconduct, defamation, naming individuals in harmful contexts.
6. Third-Party Risk: Sensitive info about people other than the speaker.
7. Family/Trauma: Abuse, neglect, estrangement, child welfare.
8. Employment: Workplace misconduct, discrimination, non-public practices.
9. Hate/Slurs: Racial, ethnic, religious slurs or outdated/harmful terminology.
10. Security: Threats, info enabling harm or retaliation.
11. Financial: Bankruptcy, debt, fraud, exploitation.
12. Intellectual Property: Trade secrets, copyright protected performances.

OUTPUT FORMAT:
Return ONLY a list of instances found in this text chunk.
Use this format for each instance:

Instance Found:
Category: [Category Name]
Quote: "[Exact quote from text]"
Risk Note: [Why this is sensitive]

If NO risks are found in this chunk, output "No risks detected in this segment."
"""

RISK_USER_PROMPT = "Analyze this transcript for sensitive content and risks based on the categories provided"

llm_to_openai = {
    DEFAULT_LARGE_CONTEXT_MODEL: "gpt-4o",
    DEFAULT_TOOL_CAPABLE_MODEL: "gpt-3.5-turbo"
}

tokens_in = 0
tokens_out = 0

try:
    import tiktoken
    def count_tokens(text, model="gpt-3.5-turbo"):
        enc = tiktoken.encoding_for_model(model)
        return len(enc.encode(text))
except ImportError:
    def count_tokens(text, model="gpt-3.5-turbo"):
        return len(text.split())
    
# --- Logging Setup ---
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)


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

def combine_llm_outputs(output_list, system_prompt, user_prompt, max_tokens=2000, temperature=0.8):
    if len(output_list) == 1:
        return output_list[0]
    report = []
    conversation_history = []
    num_messages_added_to_conversation_history_per_round = 3
    convo_history_limit = 1
    global tokens_in, tokens_out
    while len(output_list) > 0:
        if len(output_list) > 3 or len(output_list) == 2:
            output1 = output_list.pop()
            output2 = output_list.pop()
            output3 = False
        elif len(output_list) == 3:
            output1 = output_list.pop()
            output2 = output_list.pop()
            output3 = output_list.pop()
        if not output3:
            messages = [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": f"Combine these two documents into one, preserving format and content. Document one: {output1}\nDocument two: {output2}"},
            ]
        else:
            messages = [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": f"Combine these three documents into one, preserving format and content. Document one: {output1}\nDocument two: {output2}\nDocument three: {output3}"},
            ]
        if len(conversation_history) > convo_history_limit * num_messages_added_to_conversation_history_per_round:
            conversation_history = conversation_history[num_messages_added_to_conversation_history_per_round:]
        # --- STREAMING IMPLEMENTATION ---
        response = client.chat.completions.create(
            model=DEFAULT_LARGE_CONTEXT_MODEL,
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
                tokens_out += count_tokens(delta.content, model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
        summary = "".join(summary_chunks)
        if summary and '</think>' in summary:
            summary = summary.split('</think>', 1)[-1].strip()
        tokens_in += count_tokens("".join([m["content"] for m in messages]), model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
        logging.info(f"Total tokens in: {tokens_in}")
        logging.info(f"Total tokens out: {tokens_out}")
        print(f'RESPONSE:\n{summary}\n')
        report.append(summary)
        conversation_history = conversation_history + messages
        conversation_history.append({"role": "assistant", "content": summary})
    return combine_llm_outputs(report, system_prompt, user_prompt, max_tokens, temperature)


def process_file(file_path, system_prompt, user_prompt, max_tokens=2000, temperature=0.8):
    with open(file_path, 'r', encoding='utf-8') as file:
        transcript = file.read()
    conversation_history = []
    messages = [
        {"role": "system", "content": system_prompt},
        {"role": "user", "content": f"{user_prompt}. Here is the transcript: {transcript}."},
    ]
    try:
        response = client.chat.completions.create(
            model=DEFAULT_LARGE_CONTEXT_MODEL,
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
        summary = "".join(summary_chunks)
        prompt_text = "".join([m["content"] for m in messages if "content" in m])
        global tokens_in, tokens_out
        tokens_out += count_tokens(summary, model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
        tokens_in += count_tokens(prompt_text, model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
        if summary and '</think>' in summary:
            summary = summary.split('</think>', 1)[-1].strip()
        print(f'RESPONSE:\n{summary}\n')
        return summary
    except Exception as e:
        # Check for OpenAI BadRequestError due to context length
        if (
            hasattr(e, 'message') and 'maximum context length' in str(e)
        ) or (
            hasattr(e, 'args') and any('maximum context length' in str(arg) for arg in e.args)
        ):
            logging.warning("Context length exceeded, chunking transcript and merging outputs.")
            # Chunk transcript into ~8000 word pieces
            words = transcript.split()
            chunk_size = 8000
            chunks = [" ".join(words[i:i+chunk_size]) for i in range(0, len(words), chunk_size)]
            chunk_outputs = []
            for idx, chunk in enumerate(chunks):
                chunk_messages = [
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": f"{user_prompt}. Here is the transcript: {chunk}."},
                ]
                try:
                    response = client.chat.completions.create(
                        model=DEFAULT_LARGE_CONTEXT_MODEL,
                        messages=conversation_history + chunk_messages,
                        max_tokens=max_tokens,
                        temperature=temperature,
                        stream=True,
                    )
                    summary_chunks = []
                    for chunk_resp in response:
                        delta = getattr(chunk_resp.choices[0], "delta", None)
                        if delta and hasattr(delta, "content") and delta.content:
                            summary_chunks.append(delta.content)
                    summary = "".join(summary_chunks)
                    if summary and '</think>' in summary:
                        summary = summary.split('</think>', 1)[-1].strip()
                    chunk_outputs.append(summary)
                except Exception as ce:
                    logging.error(f"Error in chunk {idx+1}: {ce}")
                    chunk_outputs.append("")
            # Merge outputs using combine_llm_outputs
            merged = combine_llm_outputs(chunk_outputs, system_prompt, user_prompt, max_tokens=max_tokens, temperature=temperature)
            return merged
        else:
            raise


def process_file_json(file_path, schema, system_prompt, user_prompt, max_tokens=2000, temperature=0.8):
    with open(file_path, 'r', encoding='utf-8') as file:
        transcript = file.read()
    conversation_history = []
    report = []
    global tokens_in, tokens_out
    messages = [
        {"role": "system", "content": system_prompt},
        {"role": "user", "content": f"{user_prompt}. If the current report is referencing the same thing as the previous report, combine the reports and set the replacement flag to 'True'. Always include line numbers. Here is the transcript: {transcript}."},
    ]
    try:
        response = client.chat.completions.create(
            model=DEFAULT_LARGE_CONTEXT_MODEL,
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
        summary = "".join(summary_chunks)
        prompt_text = "".join([m["content"] for m in messages if "content" in m])
        tokens_out += count_tokens(summary, model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
        tokens_in += count_tokens(prompt_text, model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
        if summary and '</think>' in summary:
            summary = summary.split('</think>', 1)[-1].strip()
        print(f'RESPONSE:\n{summary}\n')
        try:
            summary_obj = json.loads(summary)
            if isinstance(summary_obj, dict) and "reports" in summary_obj:
                for report_obj in summary_obj["reports"]:
                    report.append(report_obj)
        except Exception as e:
            print(f"Error parsing JSON from LLM: {e}\nRaw output: {summary}")
        # Remove replaced objects from the list
        to_delete = []
        i = len(report) - 1
        while i > 0:
            if report[i].get("replacement"):
                to_delete.append(report[i-1])
            i -= 1
        for val in to_delete:
            report.remove(val)
        for report_obj in report:
            report_obj.pop('replacement', None)
        return report
    except Exception as e:
        # Check for OpenAI BadRequestError due to context length
        if (
            hasattr(e, 'message') and 'maximum context length' in str(e)
        ) or (
            hasattr(e, 'args') and any('maximum context length' in str(arg) for arg in e.args)
        ):
            logging.warning("Context length exceeded, chunking transcript and merging outputs.")
            # Chunk transcript into ~8000 word pieces
            words = transcript.split()
            chunk_size = 8000
            chunks = [" ".join(words[i:i+chunk_size]) for i in range(0, len(words), chunk_size)]
            chunk_outputs = []
            for idx, chunk in enumerate(chunks):
                chunk_messages = [
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": f"{user_prompt}. If the current report is referencing the same thing as the previous report, combine the reports and set the replacement flag to 'True'. Always include line numbers. Here is the transcript: {chunk}."},
                ]
                try:
                    response = client.chat.completions.create(
                        model=DEFAULT_LARGE_CONTEXT_MODEL,
                        messages=conversation_history + chunk_messages,
                        max_tokens=max_tokens,
                        temperature=temperature,
                        stream=True,
                    )
                    summary_chunks = []
                    for chunk_resp in response:
                        delta = getattr(chunk_resp.choices[0], "delta", None)
                        if delta and hasattr(delta, "content") and delta.content:
                            summary_chunks.append(delta.content)
                    summary = "".join(summary_chunks)
                    if summary and '</think>' in summary:
                        summary = summary.split('</think>', 1)[-1].strip()
                    chunk_outputs.append(summary)
                except Exception as ce:
                    logging.error(f"Error in chunk {idx+1}: {ce}")
                    chunk_outputs.append("")
            # Merge outputs using combine_llm_outputs
            merged = combine_llm_outputs(chunk_outputs, system_prompt, user_prompt, max_tokens=max_tokens, temperature=temperature)
            # Try to parse merged output as JSON
            try:
                summary_obj = json.loads(merged)
                if isinstance(summary_obj, dict) and "reports" in summary_obj:
                    for report_obj in summary_obj["reports"]:
                        report.append(report_obj)
            except Exception as e:
                print(f"Error parsing JSON from merged LLM output: {e}\nRaw output: {merged}")
            # Remove replaced objects from the list
            to_delete = []
            i = len(report) - 1
            while i > 0:
                if report[i].get("replacement"):
                    to_delete.append(report[i-1])
                i -= 1
            for val in to_delete:
                report.remove(val)
            for report_obj in report:
                report_obj.pop('replacement', None)
            return report
        else:
            raise


def riskalyze(transcript_path, outputs_dir, schema, system_prompt, user_prompt, combine):
    try:
        new_filename = args.output_file
        output_path = outputs_dir + '/' + new_filename
        if len(schema) > 0:
            final_summary = process_file_json(transcript_path, schema, system_prompt, user_prompt)
            print(f'final_summary:\n{final_summary}')
            print(f'Writing results to {output_path}')
            with open(output_path, 'w+', encoding='utf-8') as result_file:
                for report_obj in final_summary:
                    json.dump(report_obj, result_file, indent=2)
                    result_file.write('\n')
        else:
            final_summary = process_file(transcript_path, system_prompt, user_prompt, combine)
            print(f'final_summary:\n{final_summary}')
            print(f'Writing results to {output_path}')
            with open(output_path, 'w', encoding='utf-8') as result_file:
                result_file.write("Generated with AI assistance, please review for accuracy.\n\n")
                result_file.write(final_summary)
        return new_filename, 0
    except Exception as e:
        print(f'Error during custom job: {e}')
        print(traceback.format_exc())
        return new_filename, 1


callback_url = config.get('API Server', 'callback_url')
cat_talk_callback_url = config.get('API Server', 'callback_url_2')
updates_api_key = config.get('API Server', 'updates_api_key')

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

def update_metrics(task_id, job_type, parameters, metrics_url):
    headers = {
        'Authorization': f'Bearer {updates_api_key}',
        'Content-Type': 'application/json'   
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

def prepare_schema(schema):
    input_data = json.loads(schema)
    properties = {}
    for obj in input_data:
        property_name = obj["name"].replace(" ", "_").lower()  # Normalize names to make them JSON-key compatible
        properties[property_name] = {
            "type": obj["type"],
            "description": obj["description"]
        }
        # Explicitly add the "replacement" property to the schema
        properties["replacement"] = {
            "type": "boolean",
            "description": (
                "The function of this is to combine reports that are talking about the same thing. "
                "If a report includes all information from the previous report along with new information, "
                "this should be set to 'True'. 'False' if not"
            )
        }
        properties["lines"] = {
            "type": "array",
            "items": {
                "type": "integer"
            },
            "description": "A list of line numbers"
        }

    prepared_schema = {
    "definitions": {
        "Format": {
            "type": "object",
            "properties": properties,
            "required": list(properties.keys()),  # Ensures all fields are required
            "description": "A format report containing line details, summaries, and replacement."
            }
        },
        "type": "object",
        "properties": {
            "reports": {
                "type": "array",
                "items": {"$ref": "#/definitions/Format"},
                "description": "A list of format reports, each containing line details, summaries, and replacement."
            }
        },
        "required": ["reports"]
    }
    return json.dumps(prepared_schema, indent=2)

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
        parser.add_argument('--output_file', type=str, default='custom-job-report.txt', help='the file the results of the process go into')
        parser.add_argument('--user_visible_task_name', type=str, default='custom-job', help='The name the user sets for this job')
        parser.add_argument('--combine', type=bool, default=False, help='Whether or not the outputs from the LLM should be combined')

        # Dataset parameters
        parser.add_argument('--dataset_project', type=str, default='SpeakEZ_Datasets', help='clearml project location of dataset')
        # parser.add_argument('--dataset_name', type=str, default='oral_history', help='clearml dataset name')
        parser.add_argument('--file_id', type=str, default='fitnessgram', help='clearml dataset file id')
        # parser.add_argument('--outputs_dir', type=str, default='./outputs', help='directory of outputs of each verbatimizer step')

        # LLM parameters
        # Default to EMPTY schema so we use the Text Processing Mode (not JSON)
        parser.add_argument('--schema', type=str, default="", help='The schema the LLM output conforms to')
        
        # Inject the Risk Analysis logic into the DEFAULT value of the prompts
        parser.add_argument('--system_prompt', type=str, default=RISK_SYSTEM_PROMPT, help='the system prompt passed to the model')
        parser.add_argument('--user_prompt', type=str, default=RISK_USER_PROMPT, help='the user prompt passed to the model')

        #parser.add_argument('--clearml_cache', type=str, default=os.path.join(user_home,'.clearml/cache'), help='location of dataset')
        #parser.add_argument('--clearml_cache', type=str, default='/app/cache', help='location of dataset')
        parser.add_argument('--job_id', type=str, help='id of job')


        args = parser.parse_args()

        clearml_dataset_name = f'{args.project_id}_{args.file_id}_outputs'
        # pull the file to verbatimize from s3 and cache local to DGX
        clearml_dataset = Dataset.get(dataset_name=clearml_dataset_name, dataset_project=args.dataset_project)
        dataset_cache_path = clearml_dataset.get_local_copy()

        print('Starting ClearML Task')
        task = Task.init(project_name=args.project_name, task_name=args.task_name, output_uri='s3://s3.ai.uky.edu:443/cat-talk')
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
            if not os.path.exists(outputs_local_dir):
                os.mkdir(outputs_local_dir)

            print([args.file_id, outputs_local_dir])
            print('ls:')
            print(os.listdir(dataset_cache_path), end='\n\n')
            temp = next((f for f in  os.listdir(dataset_cache_path) if f.endswith('.transcript')), None)
            
            # Fallback if .transcript not found
            if not temp:
                 temp = next((f for f in os.listdir(dataset_cache_path) if f.endswith('.txt')), None)
            
            transcript_file_path = f'{dataset_cache_path}/{temp}'

            print(f'dataset_cache_path: {dataset_cache_path}')
            print(f'args.file_id: {args.file_id}')
            print(f'transcript_file_path: {transcript_file_path}')
            print(f'args.collection_id: {args.collection_id}')
            print(f'args.schema: {args.schema}')
            print(f'args.system_prompt: {args.system_prompt}')
            print(f'args.user_prompt: {args.user_prompt}')
            print(f'args.combine: {args.combine}')

            if len(args.schema) > 0:
                prepared_schema = prepare_schema(args.schema)
            else:
                prepared_schema = ""
            print(f'prepared_schema: {prepared_schema}')
            # json_file_path, riskalyze_rc = riskalyze(transcript_file_path, outputs_local_dir, prepared_schema, args.system_prompt, args.user_prompt, args.combine)
            # if riskalyze_rc != 0:
            #     raise ValueError(f"Training failed with riskalyze return code {riskalyze_rc}")


            # For process_file/process_file_json, count tokens in prompts and outputs
            if len(args.schema) > 0:
                # JSON mode
                with open(transcript_file_path, 'r', encoding='utf-8') as file:
                    transcript = file.read()
                system_prompt = args.system_prompt
                user_prompt = args.user_prompt
                prompt_text = f"{user_prompt}. If the current report is referencing the same thing as the previous report, combine the reports and set the replacement flag to 'True'. Always include line numbers. Here is the transcript: {transcript}."
                final_summary = process_file_json(transcript_file_path, prepared_schema, system_prompt, user_prompt, max_tokens=2000)
                # Count tokens out for all reports
                print(f'final_summary:\n{final_summary}')
                print(f'Writing results to {outputs_local_dir + "/" + args.output_file}')
                with open(outputs_local_dir + '/' + args.output_file, 'w+', encoding='utf-8') as result_file:
                    for report_obj in final_summary:
                        json.dump(report_obj, result_file, indent=2)
                        result_file.write('\n')
            else:
                # Text mode
                with open(transcript_file_path, 'r', encoding='utf-8') as file:
                    transcript = file.read()
                system_prompt = args.system_prompt
                user_prompt = args.user_prompt
                prompt_text = f"{user_prompt}. Here is the transcript: {transcript}."
                final_summary = process_file(transcript_file_path, system_prompt, user_prompt, max_tokens=2000)
                print(f'final_summary:\n{final_summary}')
                print(f'Writing results to {outputs_local_dir + "/" + args.output_file}')
                with open(outputs_local_dir + '/' + args.output_file, 'w', encoding='utf-8') as result_file:
                    result_file.write("Generated with AI assistance, please review for accuracy.\n\n")
                    result_file.write(final_summary)
            json_file_path = args.output_file
            riskalyze_rc = 0

            if (args.job_id is not None) and (control_node):

                # remove checkpoints

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

                print('Uploading risk analysis: ', outputs_local_dir)
                dataset_files = [f for f in os.listdir(outputs_local_dir) if isfile(join(outputs_local_dir, f))]
                for dataset_file in dataset_files:
                    print(f'dataset_file: {dataset_file}')
                    local_dataset_path = os.path.join(outputs_local_dir, dataset_file)
                    print(f'local_dataset_path: {local_dataset_path}')
                    remote_dataset_path = sub_dir + '/' + args.project_id + '/' + args.collection_id + '/' + dataset_file
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
        update_url = callback_url + '/updates'
        update_status(args.file_id, args.user_visible_task_name, 'complete', 'Ready for further analysis', update_url)

        custom_job_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, args.user_visible_task_name, 'complete', 'Ready for analysis', custom_job_update_url)

        print(f"[LLM METRICS] Final tokens_in: {tokens_in}, tokens_out: {tokens_out}")
        update_metrics(task_id, 'llm', {
            'tokens_in': tokens_in,
            'tokens_out': tokens_out
        }, cat_talk_callback_url + '/metrics/update')

        #print('Finished Training, cleaning files')
        #clean_paths = [custom_task_data_path, dataset_cache_path]
        #for path in clean_paths:
        #    if path is not None:
        #        if os.path.exists(path):
        #            print('Removing path:', path)
        #            shutil.rmtree(path)

        #for name, value in os.environ.items():
        #    print("{0}: {1}".format(name, value))
    except Exception as e:
        print(e)
        traceback.print_exc()
        update_url = callback_url + '/updates'
        update_status(args.file_id, args.user_visible_task_name, 'failed', '', update_url)

        custom_job_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, args.user_visible_task_name, 'failed', '', custom_job_update_url)
    
    task.close()
