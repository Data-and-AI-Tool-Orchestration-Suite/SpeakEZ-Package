#!python3
import flask # type: ignore
from flask import request, abort, jsonify # type: ignore
from flask_cors import CORS # type: ignore
import time
from apscheduler.schedulers.background import BackgroundScheduler # type: ignore
import atexit
from celery import Celery # type: ignore
import long_tasks
# from format_transcripts import create_transcript_file  # Import the function

import os
import requests
import json
import shutil
import configparser
import sys
import subprocess
import uuid
import hashlib
import uuid
import threading
import fcntl
import time
import signal
import traceback
import io

from minio import Minio
from minio.error import S3Error
from format_transcripts import convert_transcript_format  # Import the function


from long_tasks import run_verbatimizer, run_merge, run_all

from clearml import Dataset, Task


import logging
logging.basicConfig(level=logging.DEBUG, stream=sys.stdout)
logger = logging.getLogger(__name__)
logging.getLogger('apscheduler').setLevel(logging.ERROR)

app = flask.Flask("Flask API Server")
CORS(app)

config = configparser.ConfigParser()
config.read('config.ini')
server_ip = config.get('API Server', 'api_ip')
server_port = config.get('API Server', 'website_port')
api_port = config.get('API Server', 'api_port')
api_key_hash = config.get('API Server', 'api_key_hash')

s3_url = config.get('S3 Server', 's3_url').strip("'\"")
s3_url_no_path = config.get('S3 Server', 's3_url_no_path').strip("'\"")
s3_alias = config.get('S3 Server', 's3_alias').strip("'\"")
s3_user = config.get('S3 Server', 's3_user').strip("'\"")
s3_password = config.get('S3 Server', 's3_password').strip("'\"")
s3_bucket = config.get('S3 Server', 's3_audio_bucket').strip("'\"")
s3_output_bucket = config.get('S3 Server', 's3_output_bucket').strip("'\"")


audio_files = config.get('Local', 'audio_files').strip("'\"")

os.system(f'mc alias set {s3_alias}/ {s3_url} {s3_user} {s3_password}')

CLEARML_TEMPLATE_TASK_NAME = config.get('ClearML', 'template_task_name').strip("'\"")
CLEARML_PROJECT_NAME = config.get('ClearML', 'project_name').strip("'\"")
CLEARML_QUEUE_NAME = config.get('ClearML', 'queue_name').strip("'\"")

CLEARML_TASKS = {
    'verbatimizer': 'verbatimizer_template_v4',
    'merge': '',
    'riskalyzer': 'riskalyzer_template_v2',
    'describalizer': 'ohmsifier_template_v0',
    'synchronifier': 'synchronifier_template_v1',
    'custom': 'custom_job_template_v1'
}

DGX_QUEUE_PATH = './DGX_QUEUE.json'
if not os.path.exists(DGX_QUEUE_PATH):
    with open(DGX_QUEUE_PATH, 'w+') as file:
        file.write(json.dumps([]))


# File locking helper functions
def lock_file(file, timeout=5):
    start_time = time.time()
    while True:
        try:
            fcntl.flock(file, fcntl.LOCK_EX | fcntl.LOCK_NB)  # Non-blocking lock
            return  # Lock acquired, exit
        except IOError:
            if time.time() - start_time > timeout:  # Timeout exceeded
                raise TimeoutError(f"Could not acquire lock on {DGX_QUEUE_PATH} within {timeout} seconds")
            time.sleep(0.1)  # Retry after 100ms

def unlock_file(file):
    fcntl.flock(file, fcntl.LOCK_UN)

# Load the queue with file locking
def get_DGX_queue():
    with open(DGX_QUEUE_PATH, 'r+') as file:
        lock_file(file)  # Lock the file, retry for 5 seconds by default
        try:
            DGX_queue = json.load(file)
        finally:
            unlock_file(file)  # Unlock when done
    return DGX_queue

# Write to the queue with file locking
def set_DGX_queue(DGX_queue):
    with open(DGX_QUEUE_PATH, 'w') as file:
        lock_file(file)  # Lock the file, retry for 5 seconds by default
        try:
            file.write(json.dumps(DGX_queue))
        finally:
            unlock_file(file)  # Unlock when done

'''
Route Wrapper to ensure apiKey is set in the header and is correct
'''
def verify_api_key(submitted_key, valid_hashed_key=api_key_hash):
    return hash_string_md5(submitted_key) == valid_hashed_key
def require_api_key(func):
    def wrapper(*args, **kwargs):
        api_key = request.headers.get('apiKey')
        # Check if the API key is present and valid (you should implement your validation logic)
        if not api_key or not verify_api_key(api_key):
            print_flush("Unauthorized")
            abort(401)  # Unauthorized
        # If the API key is valid, proceed with the original function
        return func(*args, **kwargs)
    return wrapper


'''
Pre-request handler
'''
# @app.before_request
# def log_request_info():
#     print_flush(f"Request: {request.method} {request.url} - Headers: {request.headers}")

@app.errorhandler(404)
def page_not_found(e):
    print_flush(f"404 Error: {request.method} {request.url}")
    return {'error': "Endpoint not found"}, 404

def print_flush(x):
    print(x)
    sys.stdout.flush()

def hash_string_md5(input_string):
    md5_hash = hashlib.md5()
    md5_hash.update(input_string.encode('utf-8'))
    return md5_hash.hexdigest()


def find_key(dictionary, value):
    return next((key for key, val in dictionary.items() if val == value), None)


def schedule_background_task(function, interval_minutes):
    scheduler = BackgroundScheduler()
    scheduler.remove_all_jobs()
    scheduler.add_job(function, 'interval', minutes=interval_minutes)
    scheduler.start()
    atexit.register(lambda: scheduler.shutdown())


#@app.route('/', methods=['GET'], endpoint='index')
#@require_api_key
#def index():
#    print('index')
#    return 'HELLO WORLD'

#@app.route('/api/test/', methods=['GET'], endpoint='testing_route')
#@require_api_key
#def testing_route():
#    return {}

@app.route('/api/job/verbatimizer', methods=['POST'])
def verbatimizer():
    data = request.get_json()
    if not data:
        return jsonify({"error": "no data provided"}), 400
    
    audio_path = data.get('filepath', None)
    if not audio_path:
        return jsonify({"error": "audio path not provided"}), 400
    print(audio_files)
    os.system(f'mc cp {s3_alias}/{s3_bucket}/{audio_path} {audio_files}/{audio_path}')
    
    audio_path = f'{audio_files}/{audio_path}'

    if not os.path.isfile(audio_path):
        return jsonify({'status': 'error', 'message': 'file not found'})
    
    task = run_verbatimizer.apply_async(args=[audio_path])

    return jsonify({'status': "job started"}) # respond to the post with this

@app.route('/api/job/merge', methods=['POST'])
def merge_api():
    data = request.get_json()
    if not data:
        return jsonify({"error": "no data provided"}), 400
    

    project_id = data.get('project_id', None)
    if not project_id:
        return jsonify({"error": "Project ID not provided"}), 400
    collection_code = data.get('collection_code', None)
    if not collection_code:
        return jsonify({"error": "Collection code not provided"}), 400
    file_id = data.get('file_id', None)
    if not file_id:
        return jsonify({"error": "File ID not provided"}), 400

    
    llm_guess_weight = data.get('llm_guess_weight', .45)

    task = run_merge.apply_async(args=[project_id, collection_code, file_id, llm_guess_weight])

    return jsonify({'status': "job started"}) # respond to the post with this

# def merge(collection_code, file_id, llm_guess_weight):
#     diarize_file_path = f'output/{collection_code}/{file_id}.rttm'
#     json_file_path = f'output/{collection_code}/{file_id}.json'
#     txt_file_path = f'output/{collection_code}/{file_id}.txt'
#     transcription_file_path = f'output/{collection_code}/{file_id}_transcription.txt'
#     merged_file_path = f'output/{collection_code}/{file_id}_merged.json'
#     merge_command = f'python3 merge.py "{diarize_file_path}" "{json_file_path}" "{txt_file_path}" {llm_guess_weight} "{transcription_file_path}" "{merged_file_path}"'

@app.route('/api/job/describalizer', methods=['POST'])
def describalizer():
    # Get the JSON data from the POST request
    data = request.get_json()
    
    # Print the received data for debugging purposes
    print(f"Received data: {data}")

    # Here you can run your functions with the received data
    #result = run_describalizer(data)
    
    # Return the result as a JSON response
    return jsonify({'status': "job started"}) # respond to the post with this


@app.route('/api/job/ohmsifier', methods=['POST'])
def ohmsifier():
    # Get the JSON data from the POST request
    data = request.get_json()
    
    # Print the received data for debugging purposes
    print(f"Received data: {data}")

    # Here you can run your functions with the received data
    #result = run_ohmsifier(data)
    
    # Return the result as a JSON response
    return jsonify({'status': "job started"}) # respond to the post with this


@app.route('/api/job/riskalyzer', methods=['POST'])
def riskalyzer():
    # Get the JSON data from the POST request
    data = request.get_json()
    
    # Print the received data for debugging purposes
    print(f"Received data: {data}")

    # Here you can run your functions with the received data
    #result = run_riskalyzer(data)
    
    # Return the result as a JSON response
    return jsonify({'status': "job started"}) # respond to the post with this


@app.route('/api/job/synchronifier', methods=['POST'])
def synchronifier():
    # Get the JSON data from the POST request
    data = request.get_json()
    
    # Print the received data for debugging purposes
    print(f"Received data: {data}")

    # Here you can run your functions with the received data
    #result = run_synchronifier(data)
    
    # Return the result as a JSON response
    return jsonify({'status': "job started"}) # respond to the post with this


'''
DGX Queue Management
'''
def get_task_ids_by_status(status):
    task_filter = {
        'status': [status]
    }
    tasks_ids = Task.query_tasks(project_name = CLEARML_PROJECT_NAME, task_filter = task_filter)
    return tasks_ids

@app.route("/get-queued-jobs", methods=["GET"], endpoint='get_queued_jobs_api')
@require_api_key
def get_queued_jobs_api():
    clearml_queue_ids = get_task_ids_by_status('queued')
    clearml_queue = []
    for id in clearml_queue_ids:
        task_name = Task.get_task(task_id=id).name
        clearml_queue.append(task_name)
    tasks = {}
    for i in range(len(clearml_queue)):
        task_id = clearml_queue_ids[i]
        task_name = clearml_queue[i]
        task = Task.get_task(task_id = task_id, project_name = CLEARML_PROJECT_NAME)
        if task:
            params = task.get_parameters()
            print_flush(json.dumps(params, indent=2))
            tasks[task_name] = params
    return tasks

@app.route("/update-DGX-queue", methods=["POST"], endpoint='update_dgx_queue_api')
@require_api_key
def update_dgx_queue_api():
    data = request.json
    if 'ids' in data:
        DGX_queue = get_DGX_queue()
        for id in data['ids']:
            DGX_queue.append(id)
        set_DGX_queue(DGX_queue)
        return {'success': True, 'message': 'Added IDs'}
    else:
        return {'success': True, 'message': 'No IDs to Add'}



'''
ClearML Handling
'''
@app.route('/create-clearml-dataset', methods=['POST'], endpoint='create_clearml_dataset')
@require_api_key
def create_clearml_dataset():
    data = request.get_json()
    if not data:
        return jsonify({"error": "No data provided"}), 400
    file_id = data.get('file_id', None)
    if not file_id:
        return jsonify({"error": "File ID not provided"}), 400
    collection_code = data.get('collection_code', None)
    if not collection_code:
        return jsonify({"error": "Collection code not provided"}), 400
    dataset_name = data.get('dataset_name', None)
    if not dataset_name:
        return jsonify({"error": "Dataset name not provided"}), 400
    s3_bucket_name = data.get('bucket_name', None)
    if not s3_bucket_name:
        return jsonify({"error": "S3 bucket name not provided"}), 400
    s3_file_name = data.get('file_name', None)
    if not s3_file_name:
        return jsonify({"error": "File name not provided"}), 400
    synchronifier = data.get('synchronifier', None)
    print(f'\n\nDATA:\n{data}\n\n')
    print(f'synchronifier: {synchronifier}')
    file_basename, file_extension = os.path.splitext(s3_file_name)
    # dataset_path = str(data.get('dataset_path', None))
    # if not dataset_path:
    #     return jsonify({"error": "Dataset path not provided"}), 400
    
    print("================================")
    print("create dataset")
    dataset = Dataset.create(
        dataset_name=dataset_name, dataset_project="SpeakEZ_Datasets"
    )

    if synchronifier:
        source_url = s3_url.replace('https', 's3').replace('http', 's3')+s3_bucket_name+'/'+collection_code+'/'+file_id
        source_url = source_url.rstrip('/')
    else:
        source_url = s3_url.replace('https', 's3').replace('http', 's3')+s3_bucket_name+'/'+collection_code+'/'+file_id+file_extension
        source_url = source_url.rstrip('/')
    print("================================")
    print(source_url, s3_file_name)
    dataset.add_external_files(source_url = [source_url], dataset_path = [''], verbose=True)
    # print(dataset.list_files())
    dataset.upload()
    dataset.finalize()

    return jsonify({'success': True}), 200


@app.route('/api/test', methods=['POST'], endpoint='test')
@require_api_key
def test():
    # clearml_dataset = Dataset.get(dataset_name='oral_history', dataset_project="SpeakEZ_Datasets")
    # print(clearml_dataset)
    # dataset_cache_path = clearml_dataset.get_local_copy()
    # print_flush(dataset_cache_path)
    # return jsonify({'success': True}), 200
    return {}

@app.route('/start-all-tasks', methods=['POST'], endpoint='start_all_tasks_api')
@require_api_key
def start_all_tasks_api():
    print_flush(request.get_json())
    data = request.get_json()
    if not data:
        return jsonify({"error": "No data provided"}), 400
    task_name = str(data.get('task_name', None))
    if not task_name:
        return jsonify({"error": "Task name not provided"}), 400
    print(f'task_name: {task_name}')
    # A user can either run all tasks starting with the verbatimizer or the synchronifier
    if task_name not in ['verbatimizer', 'synchronifier']:
        return jsonify({"error": f"Invalid task provided. Choose from one of {['verbatimizer', 'synchronifier']}"}), 400
    project_id = str(data.get('project_id', None))
    if not project_id:
        return jsonify({"error": "Project ID not provided"}), 400
    collection_id = str(data.get('collection_id', None))
    if not collection_id:
        return jsonify({"error": "Collection ID not provided"}), 400
    file_id = str(data.get('file_id', None))
    if not file_id:
        return jsonify({"error": "File ID not provided"}), 400
    file_name = str(data.get('file_name', None))
    if not file_name:
        return jsonify({"error": "File name not provided"}), 400
    synchronifier_transcript_file = str(data.get('synchronifier_transcript_file', None))
    parameters = data.get('parameters', {})
    if type(parameters) != dict:
        return jsonify({"error": "Provided parameters must be in a dictionary"}), 400
    file_basename, file_extension = os.path.splitext(file_name)

    task_id = run_all.apply_async(args=[task_name, project_id, collection_id, file_id, file_extension, parameters, synchronifier_transcript_file])
    print(f'task_id: {task_id}')
    return jsonify({"task-id": str(task_id), "success": True})
    
    #task_id = start_clearml_task(task_name, template_task, project_id, collection_id, file_id, file_extension, parameters, synchronifier_transcript_file)
    #print_flush(task_id)
    #if not task_id:
    #    print_flush({'error': 'Failed to create task'})
    #    return {'error': 'Failed to create task'}
    #return {'task_id': task_id}

@app.route('/start-task', methods=['POST'], endpoint='start_task_api')
@require_api_key
def start_task_api():
    print_flush(request.get_json())
    data = request.get_json()
    if not data:
        return jsonify({"error": "No data provided"}), 400
    task_name = str(data.get('task_name', None))
    if not task_name:
        return jsonify({"error": "Task name not provided"}), 400
    print("task-name: ", task_name)
    if task_name not in CLEARML_TASKS and 'custom' not in task_name[:6]:
        return jsonify({"error": f"Invalid task provided. Choose from one of {json.dumps(CLEARML_TASKS)}"}), 400
    else:
        task_to_get = task_name
        if 'custom' == task_name[:6]:
            task_to_get = 'custom' 
        template_task = CLEARML_TASKS[task_to_get]
    project_id = str(data.get('project_id', None))
    if not project_id:
        return jsonify({"error": "Project ID not provided"}), 400
    collection_id = str(data.get('collection_id', None))
    if not collection_id:
        return jsonify({"error": "Collection ID not provided"}), 400
    file_id = str(data.get('file_id', None))
    if not file_id:
        return jsonify({"error": "File ID not provided"}), 400
    file_name = str(data.get('file_name', None))
    if not file_name:
        return jsonify({"error": "File name not provided"}), 400
    synchronifier_transcript_file = str(data.get('synchronifier_transcript_file', None))
    parameters = data.get('parameters', {})
    if type(parameters) != dict:
        return jsonify({"error": "Provided parameters must be in a dictionary"}), 400
    file_basename, file_extension = os.path.splitext(file_name)
    # file_extension = '.' + file_extension


    task_id = start_clearml_task(task_name, template_task, project_id, collection_id, file_id, file_extension, parameters, synchronifier_transcript_file)
    print_flush(task_id)
    if not task_id:
        print_flush({'error': 'Failed to create task'})
        return {'error': 'Failed to create task'}
    return {'task_id': task_id}

@app.route('/cancel-celery-task', methods=['POST'], endpoint='cancel_celery_task_api')
@require_api_key
def cancel_celery_task_api():
    print_flush(f'Cancelling celery task: ')
    print_flush(request.get_json())
    data = request.get_json()
    if not data:
        return jsonify({"error": "No data provided"}), 400
    task_id = str(data.get('task-id', None))
    if not task_id:
        return jsonify({"error": "task-id not provided"}), 400
    print_flush(f"task-id: {task_id}")
    job_name = str(data.get('job-name', None))
    if not job_name:
        # long_tasks.celery_app.control.revoke(task_id=task_id, terminate=True, signal='SIGINT')
        return jsonify({"error": "job-name not provided"}), 400
    else:
        print_flush(f"job_name: {job_name}")
        job_dict = {
            'verbatimizer': signal.SIGUSR1,
            'synchronifier': signal.SIGUSR1,
            'riskalyzer': signal.SIGUSR2,
            'describalizer': signal.SIGRTMIN
        }
        # long_tasks.celery_app.control.revoke(task_id=task_id, terminate=True, signal=job_dict[job_name])
        # inspector = long_tasks.celery_app.control.inspect()
        result = long_tasks.celery_app.control.inspect().query_task(task_id)
        print(f'long tasks: {result}')
        for hostname, tasks in result.items():
            print(f'hostname: {hostname}')
            print(f'tasks: {tasks}')
            try:
                worker_pid = tasks[task_id][1]['worker_pid']
            except:
                return jsonify({"error": "task not found"}), 400
            container_id = hostname.split('@')[1]
            print_flush(f"worker PID: {worker_pid} in container: {container_id}")
            
            # Using subprocess to send kill signal inside the correct container
            import subprocess
            signal_num = job_dict[job_name]
            # this does not actually kill the process. Just sends the signal which the celery job then catches and uses to abort the clearml tasks.
            subprocess.run(['docker', 'exec', container_id, 'kill', f'-{signal_num}', str(worker_pid)])

    
    return {'success': "true"}


@app.route('/cancel-task', methods=['POST'], endpoint='cancel_task_api')
@require_api_key
def cancel_task_api():
    print_flush(request.get_json())
    data = request.get_json()
    if not data:
        return jsonify({"error": "No data provided"}), 400
    task_id = str(data.get('task_id', None))
    if not task_id:
        return jsonify({"error": "task_id not provided"}), 400
    print("task-id: ", task_id)

    result = cancel_clearml_task(task_id)
    print_flush(f"SUCCESS: {result}")
    if not result['success']:
        print_flush({'error': 'Failed to cancel task'})
        return {'error': 'Failed to cancel task', 'success': False}
    return {'success': "true"}

def cancel_clearml_task(task_id):
    global CLEARML_PROJECT_NAME
    try:
        task = Task.get_task(project_name=CLEARML_PROJECT_NAME, task_id = task_id)
        success = task.mark_completed(force=True)
        print(f"SUCCESS: {success}")
        return {'success': success}
    except:
        return {"success": False}
    

def start_clearml_task(task_name, template_task_name, speakez_project_id, collection_id, file_id, file_extension, params={}, synchronifier_transcript_file=None):
    global CLEARML_PROJECT_NAME
    # base_project_name = 'llm_factory_trainer'
    # base_task_name = 'trainer_template_v0'
    print(template_task_name)
    template_task = Task.get_task(project_name=CLEARML_PROJECT_NAME, task_name=template_task_name)

    print(template_task)
    if template_task == None:
        return None
    project_id = template_task.get_project_id(CLEARML_PROJECT_NAME)
    print('Template project_id: ' + project_id)
    trainer_task_name = task_name + '-' + file_id + '_' + str(uuid.uuid4())
    if 'custom' == task_name[:6]:
        user_visible_task_name = task_name[7:]
    else:
        user_visible_task_name = task_name
    cloned_task = Task.clone(
        source_task=template_task,
        name=trainer_task_name,
        comment='automatically created task based on a template',
        project=project_id,
    )
    parameters = template_task.get_parameters(CLEARML_PROJECT_NAME)

    parameters['Args/task_name'] = trainer_task_name
    parameters['Args/project_id'] = speakez_project_id
    parameters['Args/collection_id'] = collection_id
    parameters['Args/file_id'] = file_id
    parameters['Args/file_extension'] = file_extension
    parameters['Args/output_file'] = file_id + '_sensitivity.txt'
    parameters['Args/user_visible_task_name'] = user_visible_task_name
    parameters['Args/noDelete'] = False
    if synchronifier_transcript_file:
        parameters['Args/synchronifier_transcript_file'] = synchronifier_transcript_file

    for opt, value in params.items():
        try:
            if type(value) == str:
                if '.' in value:
                    parameters[f'Args/{opt}'] = float(value)
                elif value == 'True':
                    parameters[f'Args/{opt}'] = True
                elif value == 'False':
                    parameters[f'Args/{opt}'] = False
                else:
                    parameters[f'Args/{opt}'] = int(value)
            else:
                parameters[f'Args/{opt}'] = value
        except:
            parameters[f'Args/{opt}'] = value



    file_paths = []

    parameters['Args/job_id'] = file_id


    print_flush('Starting ClearML Task')
    
    cloned_task.set_parameters(parameters)
    Task.enqueue(
        task=cloned_task,
        queue_name=CLEARML_QUEUE_NAME,
        queue_id=None
    )
    
    print('Update Job Status')
    # print(update_job_status(job_id, 'Job queued').content)
    print('Enqueued Job')

    print(f'{cloned_task.id=}')
    return {'task_id': cloned_task.id}


@app.route('/create-formatted-transcripts', methods=['POST'], endpoint='create_formatted_transcripts')
@require_api_key
def create_formatted_transcripts():
    '''
    s3_url
    s3_alias
    s3_user
    s3_password
    s3_bucket
    '''

    client = Minio(
        s3_url_no_path,
        access_key=s3_user,
        secret_key=s3_password,
        secure=True
    )


    data = request.get_json()
    if not data:
        return jsonify({"error": "No data provided"}), 400

    # Extract required parameters
    project_id = str(data.get('project_id', None))
    if not project_id:
        return jsonify({"error": "Project ID not provided"}), 400
    collection_code = str(data.get('collection_code', None))
    if not collection_code:
        return jsonify({"error": "Collection code not provided"}), 400
    file_name = str(data.get('file_name', None))
    if not file_name:
        return jsonify({"error": "File name not provided"}), 400

    # Construct file paths
    merged_file_path = f'output/{collection_code}/{file_name}'
    general_transcript_path = f'{project_id}/{collection_code}/{file_name}'

    try:
        response = client.get_object(s3_output_bucket, general_transcript_path)
        content = response.read().decode('utf-8')
    except:
        return jsonify({"error": f"Failed to create formatted transcripts: {str(e)}"}), 500

    # Call the create_transcript_file function
    try:
        name_without_extension = os.path.splitext(file_name)[0]

        webvtt_transcript = convert_transcript_format(content, 'WEBVTT')
        webvtt_transcript_path = f'{project_id}/{collection_code}/{name_without_extension}_trnd_mach.vtt'

        txt_transcript = convert_transcript_format(content, 'txt')
        txt_transcript_path = f'{project_id}/{collection_code}/{name_without_extension}_trnd_mach.txt'

        bbt_transcript = convert_transcript_format(content, 'bbt')
        bbt_transcript_path = f'{project_id}/{collection_code}/{name_without_extension}_trnd_mach_bbt.txt'

        srt_transcript = convert_transcript_format(content, 'srt')
        srt_transcript_path = f'{project_id}/{collection_code}/{name_without_extension}_trnd_mach_capt.vtt'

        webvtt_caption_transcript = convert_transcript_format(content, 'WEBVTT-caption')
        webvtt_caption_transcript_path = f'{project_id}/{collection_code}/{name_without_extension}_trnd_mach_capt_vtt.vtt'

        client.put_object(s3_output_bucket, webvtt_transcript_path, io.BytesIO(webvtt_transcript.encode('utf-8')), length=len(webvtt_transcript.encode('utf-8')))
        client.put_object(s3_output_bucket, txt_transcript_path, io.BytesIO(txt_transcript.encode('utf-8')), length=len(txt_transcript.encode('utf-8')))
        client.put_object(s3_output_bucket, bbt_transcript_path, io.BytesIO(bbt_transcript.encode('utf-8')), length=len(bbt_transcript.encode('utf-8')))
        client.put_object(s3_output_bucket, srt_transcript_path, io.BytesIO(srt_transcript.encode('utf-8')), length=len(srt_transcript.encode('utf-8')))
        client.put_object(s3_output_bucket, webvtt_caption_transcript_path, io.BytesIO(webvtt_caption_transcript.encode('utf-8')), length=len(webvtt_caption_transcript.encode('utf-8')))

        return jsonify({"success": True, "message": "Formatted transcripts created and uploaded to S3"}), 200
    except Exception as e:
        print_flush(f"Error: {str(e)}")
        traceback.print_exc()
        return jsonify({"error": f"Failed to create formatted transcripts: {str(e)}"}), 500


if __name__ == "__main__":
    debug = True
    print('Starting server')
    

    app.run(host="0.0.0.0", port=int(api_port), debug = debug)

