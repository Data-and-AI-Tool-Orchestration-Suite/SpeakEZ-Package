import requests
import json
import subprocess
import time
from datetime import datetime
import configparser
import yaml
import os

config = configparser.ConfigParser()
config.read('/workspace/config.ini')
apiKey = config.get('API Server', 'api_key')

job_types_str = config.get('Jobs', 'job_types')
jobs = [j.strip() for j in job_types_str.split(',')]
max_workers = config.getint('Jobs', 'max_workers', fallback=4)
SLEEP_TIME = 30 # seconds

while True:
    url = 'https://cat-talk.ai.uky.edu/api/get-queued-jobs'

    headers = {
            'apiKey': apiKey
    }

    data = None
    with requests.get(url, params=None, headers=headers) as response:

        if response.status_code != 200: # check if http request was successful. exit if not.
            print(f'Error: {response.status_code}')
            print(response.text)
            time.sleep(SLEEP_TIME)
            continue

        try:
            #print(response.text)
            ()
        except:
            ()
        data = response.json()
    for i in data:
        ()
        #print(i)
    if len(data) == 0:
        print('No jobs in queue')
        time.sleep(SLEEP_TIME)
        continue
    ids = []
    for key in data:
        ids.append(key)
    #print(ids)

    success_ids = []
    for id in ids:
        clearml_config_file = os.environ.get("CLEARML_CONFIG_FILE", "/workspace/clearml.conf")
        env = os.environ.copy()

        if not id.startswith(tuple(jobs)): # continue if job cannot be run on this machine
            continue

        # MODIFIED PART: Use the --config-file flag for reliability
        # This directly tells the agent which config to use, overriding other settings.
        command_with_config = [
            'clearml-agent',
            '--config-file',
            clearml_config_file,
            'list'
        ]
        workers_result = subprocess.run(command_with_config, stdout=subprocess.PIPE, env=env).stdout.decode('utf-8') # check num workers running

        workers_dict = yaml.safe_load(workers_result.strip())
        num_workers = 0
        for worker in workers_dict['workers']:
            for job_type in jobs:
                if job_type == worker['task']['name'][:len(job_type)]:
                    num_workers += 1
                    continue
        print(f'num_workers: {num_workers}')
        if num_workers >= max_workers:
            print(f'|{datetime.now().strftime("%H:%M:%S")}| max workers reached ({num_workers}), skipping {id}...')
            continue
        print(f'|{datetime.now().strftime("%H:%M:%S")}| queuing {id}...')
        command = f'/workspace/execute_job.sh {id}'
        process = subprocess.Popen(command, shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        time.sleep(1)
        success = False
        if process.poll() is not None and process.returncode != 0:
            print(f'Error submitting job {id}')
        else:
            success_ids.append(id)

    time.sleep(SLEEP_TIME)