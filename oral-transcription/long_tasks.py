from celery import Celery # type: ignore
import configparser
import requests
import subprocess
import threading
import os
import time
from clearml import Dataset, Task
import uuid
import shutil
from multiprocessing import Process, Manager
import sys
import redis
import json
import signal


def cancel_clearml_task(task_id):
    global CLEARML_PROJECT_NAME
    try:
        task = Task.get_task(project_name=CLEARML_PROJECT_NAME, task_id = task_id)
        success = task.mark_completed(force=True)
        print(f"SUCCESS: {success}")
        return {'success': success}
    except:
        return {"success": False}


def print_flush(x):
    print(x)
    sys.stdout.flush()




config = configparser.ConfigParser()
config.read('config.ini')
server_ip = config.get('API Server', 'docker_ip').strip("'\"")
callback_url = config.get('API Server', 'callback_url').strip("'\"")
api_key = config.get('API Server', 'api_key').strip("'\"")

s3_url = config.get('S3 Server', 's3_url').strip("'\"")
s3_alias = config.get('S3 Server', 's3_alias').strip("'\"")
s3_user = config.get('S3 Server', 's3_user').strip("'\"")
s3_password = config.get('S3 Server', 's3_password').strip("'\"")
s3_bucket = config.get('S3 Server', 's3_output_bucket').strip("'\"")

CLEARML_TEMPLATE_TASK_NAME = config.get('ClearML', 'template_task_name').strip("'\"")
CLEARML_PROJECT_NAME = config.get('ClearML', 'project_name').strip("'\"")
CLEARML_QUEUE_NAME = config.get('ClearML', 'queue_name').strip("'\"")

update_url = callback_url + '/updates'

os.system(f'mc alias set {s3_alias}/ {s3_url} {s3_user} {s3_password}')

celery_app = Celery('api', broker=f'redis://{server_ip}:6379/0')


CLEARML_TASKS = {
    'verbatimizer': 'verbatimizer_template_v3',
    'merge': '',
    'riskalyzer': 'riskalyzer_template_v1',
    'describalizer': 'describalizer_template_v0',
    'synchronifier': 'synchronifer_template_v0',
    'custom': 'custom_job_template_v0'
}

# Function to update the status of the job to the frontend
def update_status(file_id, job, status, message, update_url):

    headers = {
        'Authorization': f'Bearer {api_key}',
        'Content-Type': 'application/json'  # Ensure the content type is JSON
    }

    payload = {
        'status': status,
        'job': job,
        'file_id': file_id,
        'message': message,
    }
    try:
        response = requests.post(update_url, headers=headers, json=payload)
        print(response)
        response.raise_for_status()  # Raise an error for bad responses
    except requests.RequestException as e:
        print(f"Error updating status: {e}")

# Function to launch job scripts 
def run_job(command, result_container):
    try:
        result = subprocess.run(
            command,
            capture_output=True,
            text=True,
            shell=True
        )
        result_container['stdout'] = result.stdout
        result_container['stderr'] = result.stderr
        result_container['returncode'] = result.returncode
        
        return result_container
    except Exception as e:
        return "", str(e), 1


@celery_app.task(bind=True)
def example_celery_task(arg):
    pass

@celery_app.task(bind=True)
def run_verbatimizer(file_path):
    verbatimize_update_url = callback_url + '/updates/verbatimizer'
    diarize_result = {}
    transcribe_result = {}
    diarize_command = f'python3 diarize.py "{file_path}"'
    transcribe_command = f'python3 transcribe.py "{file_path}"'

    print('starting diarize_thread...')
    diarize_thread = threading.Thread(target=run_job, args=(diarize_command, diarize_result))
    print('starting transcribe_thread...')
    transcribe_thread = threading.Thread(target=run_job, args=(transcribe_command, transcribe_result))

    diarize_thread.start()
    transcribe_thread.start()

    diarize_thread.join()
    transcribe_thread.join()
    diarization_success = False if diarize_result['returncode'] != 0 else True
    transcription_success = False if transcribe_result['returncode'] != 0 else True
    if not diarization_success or not transcription_success:
        if not diarization_success:
            message = 'diarization failed'
        if not transcription_success:
            message = 'transcription failed'
        if not diarization_success and not transcription_success:
            message = 'diarization and transcription failed'
        update_status(file_path, 'verbatimizer', 'Error', message, verbatimize_update_url)
        print(transcribe_result['stderr'])
        return

    diarize_file_path = f'output/{os.path.splitext(os.path.basename(file_path))[0]}.rttm'
    json_file_path = f'output/{os.path.splitext(os.path.basename(file_path))[0]}.json'
    txt_file_path = f'output/{os.path.splitext(os.path.basename(file_path))[0]}.txt'
    merge_command = f'python3 merge.py "{diarize_file_path}" "{json_file_path}" "{txt_file_path}"'

    print('starting merge_job...')
    merge_result = {}
    run_job(merge_command, merge_result)

    print('MERGE STDOUT:')
    print(merge_result['stdout'])
    print('MERGE STDERR')
    print(merge_result['stderr'])
    if merge_result['returncode'] != 0:
        update_status(file_path, 'verbatimizer', 'Error', 'Merging failed', verbatimize_update_url)
        return # do not continue to run job on errors

    # On success:
    output_dir = 'output'
    filename_no_ext, _ = os.path.splitext(json_file_path)
    transcript_file = f'{output_dir}/{filename_no_ext}_full_transcript_diarized.txt'
    combined_file = f'{output_dir}/{filename_no_ext}_combo_merge.json'
    
    print('we did it!')
    update_status(file_path, 'verbatimizer', 'Complete', '', verbatimize_update_url)

@celery_app.task(bind=True)
def run_merge(project_id, collection_code, file_id, llm_guess_weight, s3_output_bucket="output", local_outputs_path="output"):
    verbatimize_update_url = callback_url + '/updates/verbatimizer'

    outputs_path = f'{local_outputs_path}/'
    if not os.path.isdir(outputs_path):
        os.mkdir(outputs_path)

    s3_file_base = f'{s3_alias}/{s3_output_bucket}/{project_id}/{collection_code}/{file_id}'


    diarize_file_path = f'{outputs_path}/{file_id}.rttm'
    os.system(f'mc cp {s3_file_base}.rttm {diarize_file_path}')

    json_file_path = f'{outputs_path}/{file_id}.json'
    os.system(f'mc cp {s3_file_base}.json {json_file_path}')

    txt_file_path = f'{outputs_path}/{file_id}.txt'
    os.system(f'mc cp {s3_file_base}.txt {txt_file_path}')

    transcription_file_path = f'{outputs_path}/{file_id}_transcription.txt'
    merged_file_path = f'{outputs_path}/{file_id}_merged.json'
    
    merge_command = f'python3 merge.py "{diarize_file_path}" "{json_file_path}" "{txt_file_path}" {llm_guess_weight} "{transcription_file_path}" "{merged_file_path}"'
    
    print('starting merge_job...')
    merge_result = {}
    run_job(merge_command, merge_result)


    print('MERGE STDOUT:')
    print(merge_result['stdout'])
    print('MERGE STDERR')
    print(merge_result['stderr'])
    if merge_result['returncode'] != 0:
        update_status(file_id, 'verbatimizer', 'Error', 'Merging failed', verbatimize_update_url)
        return # do not continue to run job on errors
    
    os.system(f'mc cp {transcription_file_path} {s3_file_base}_transcription.txt ')
    os.system(f'mc cp {merged_file_path} {s3_file_base}_merged.json ')

    # On success:
    # output_dir = 'output'
    # filename_no_ext, _ = os.path.splitext(json_file_path)
    # transcript_file = f'{output_dir}/{filename_no_ext}_full_transcript_diarized.txt'
    # combined_file = f'{output_dir}/{filename_no_ext}_combo_merge.json'
    
    print('we did it!')
    update_status(file_id, 'verbatimizer', 'Complete', '', verbatimize_update_url)


@celery_app.task(bind=True)
def run_all(self, task_name, project_id, collection_id, file_id, file_extension, parameters, synchronifier_transcript_file):
    # def signal_handler(sig, frame): # signal handler to cancel all clearml tasks
    #     for job in running_clearml_jobs:
    #         print_flush(f'cancelling clearml task {job}')
    #         cancel_clearml_task(running_clearml_jobs[job])
    #     return
    # signal.signal(signal.SIGINT, signal_handler)

    def signal_handler_verbatimizer(sig, frame):
        job_status_list = []
        for job in job_status_dict:
            job_status_list.append(job)
        for job in job_status_list:
            update_status(file_id, job, "cancelled", '', update_url)
            del job_status_dict[job]
        job_list = []
        for job in running_clearml_jobs:
            job_list.append(job)
        for job in job_list:    
            cancel_clearml_task(running_clearml_jobs[job])
        return
    signal.signal(signal.SIGUSR1, signal_handler_verbatimizer)

    def signal_handler_riskalyzer(sig, frame):
        job_name = 'custom-riskalyzer'
        if job_name in job_status_dict:
            update_status(file_id, 'riskalyzer', "cancelled", '', update_url)
            del job_status_dict[job_name]
        if job_name in running_clearml_jobs:
            print(f'cancelling job {job_name} with clearml task id {running_clearml_jobs[job_name]}\njob_status_dict: {job_status_dict}')
            cancel_clearml_task(running_clearml_jobs[job_name])
            del running_clearml_jobs[job_name]
        print(f'job_status_dict: {job_status_dict}')
        print(f'running_clearml_jobs: {running_clearml_jobs}')
    signal.signal(signal.SIGUSR2, signal_handler_riskalyzer)

    def signal_handler_describalizer(sig, frame):
        job_name = 'describalizer'
        if job_name in job_status_dict:
            update_status(file_id, job_name, "cancelled", '', update_url)
            del job_status_dict[job_name]
        if job_name in running_clearml_jobs:
            print(f'cancelling job {job_name} with clearml task id {running_clearml_jobs[job_name]}\njob_status_dict: {job_status_dict}')
            cancel_clearml_task(running_clearml_jobs[job_name])
            del running_clearml_jobs[job_name]
        print(f'job_status_dict: {job_status_dict}')
        print(f'running_clearml_jobs: {running_clearml_jobs}')
    signal.signal(signal.SIGRTMIN, signal_handler_describalizer)

    # task name will just be the starting task
    if task_name == 'verbatimizer':
        job_status_dict = {'verbatimizer': False, 'describalizer': False, 'custom-riskalyzer': False}
    elif task_name == 'synchronifier':
        job_status_dict = {'synchronifier': False, 'describalizer': False, 'custom-riskalyzer': False}
    else:
        print('error: task_name invalid')
        return

    bool_to_status_dict = {True: "complete", False: "failed"}
    running_clearml_jobs = {}
    celery_task_id = self.request.id
    for job in job_status_dict:
        if job == 'custom-riskalyzer':
            template_task = CLEARML_TASKS[job[:6]]
        else:
            template_task = CLEARML_TASKS[job]
        task_id_dict = start_clearml_task(job, template_task, project_id, collection_id, file_id, file_extension, parameters, synchronifier_transcript_file) # start the task
        print(f'job: {job}')
        print(f'task_id_dict: {task_id_dict}')
        if not task_id_dict:
            print({'error': 'Failed to create task'}) # handle job failing to start here
            for job in job_status_dict:
                update_status(file_id, job, bool_to_status_dict[job_status_dict[job]], '', update_url)
            return
        task_id = task_id_dict['task_id']
        print(f'task_id: {task_id}')
        running_clearml_jobs[job] = task_id
        print(running_clearml_jobs)
        while not job_status_dict[job]:
            print('sleeping...')
            time.sleep(120) # sleep for two minutes before checking job status
            task = Task.get_task(task_id=task_id)
            task_status = task.get_status()
            print(f'checking task {task_id} status:\n{task_status}')
            if task_status == 'stopped' or task_status == 'closed' or task_status == 'failed':
                print({'error': f'task {task_id} failed'})
                update_status(file_id, job, 'Failed', '', update_url)
                return
            elif task_status == 'completed':
                job_status_dict[job] = True
        if job in running_clearml_jobs:
            del running_clearml_jobs[job]


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
    parameters['Args/output_file'] = task_name + '-' + file_id + '.report'
    parameters['Args/user_visible_task_name'] = user_visible_task_name
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


    print('Starting ClearML Task')

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

            



@celery_app.task(bind=True)
def run_describalizer(data):
    ()

@celery_app.task(bind=True)
def run_ohmsifier(file_path):
    ()

@celery_app.task(bind=True)
def run_riskalyzer():
    ()

@celery_app.task(bind=True)
def run_synchronifier():
    ()



