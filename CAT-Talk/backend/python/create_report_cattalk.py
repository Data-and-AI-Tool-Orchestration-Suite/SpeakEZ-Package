from datetime import date
from dateutil.relativedelta import relativedelta
import os
import configparser
import requests
import json
import pandas as pd
from reportlab.pdfgen import canvas
from reportlab.lib.units import inch
from reportlab.lib.pagesizes import letter
import numpy as np

config = configparser.ConfigParser()
script_dir = os.path.dirname(os.path.abspath(__file__))
config_filepath = os.path.join(script_dir, 'config.ini')
config.read(config_filepath)
api_key = config.get('API Server', 'api_key')
root_url = 'https://cat-talk.ai.uky.edu'
headers = {'Authorization': f'Bearer {api_key}', 'Accept': 'application/json'}

def parse_json(json_string):
    if isinstance(json_string, dict):
        return json_string
    if isinstance(json_string, list) or pd.isna(json_string):
        return None
    try:
        return json.loads(json_string)
    except (json.JSONDecodeError, TypeError):
        return None

def getNewUsers(starting_date):
    response = requests.get(root_url + '/users/get-by-timestamp', params={'starting_date': starting_date}, headers=headers)

    if response.status_code == 200:
        response_json = json.loads(response.text)
        users = response_json['users']
        if len(users) > 0:
            users_df = pd.DataFrame(users)
            users_df = users_df[['id', 'fullname', 'eppn', 'addedTimestamp', 'idpname']]
        else:
            users_df = pd.DataFrame(columns=['id', 'fullname', 'eppn', 'addedTimestamp', 'idpname'])
    else:
        if response.status_code == 403:
            print('Unauthorized to retrieve report')
            exit()
        print('Query to get new users unsuccessful')
        print(response.text)
        users_df = pd.DataFrame(columns=['id', 'fullname', 'eppn', 'addedTimestamp', 'idpname'])
    return users_df

def getNewMetrics(starting_date):
    response = requests.get(root_url + '/metrics/get_recent_metrics', params={'starting_date': starting_date}, headers=headers)
    response_json = json.loads(response.text)
    if response.status_code == 200:
        metrics = response_json['metrics']
        if len(metrics) > 0:
            metrics_df = pd.DataFrame(metrics)
        else:
            metrics_df = pd.DataFrame(columns=['taskId', 'details', 'userId', 'created_at'])
    else:
        if response.status_code == 403:
            print('Unauthorized to retrieve report')
            exit()
        print('Query to get new actions unsuccessful')
        print(response.text)
        metrics_df = pd.DataFrame(columns=['taskId', 'details', 'userId', 'created_at'])
    return metrics_df


if __name__ == '__main__':

    starting_date = ''
    reports_directory = 'reports'
    current_date = date.today()
    filename = f'cattalk_report_{current_date.strftime('%m%d%Y')}.pdf'
    if starting_date == '':
        starting_date = (current_date - relativedelta(months=2)).strftime('%m/%d/%Y')
    users_df = getNewUsers(starting_date)
    metrics_df = getNewMetrics(starting_date)

    user_bullets = []
    if len(users_df) == 1:
        user_bullets.append(f'{len(users_df):,} new user added to self-service tool.')
    else:
        user_bullets.append(f'{len(users_df):,} new users added to self-service tool.')
    if len(users_df) > 0:
        if len(users_df['idpname'].unique()) == 1:
            user_bullets.append(f'New user(s) added from {len(users_df['idpname'].unique())} institution: {users_df['idpname'].unique()[0]}.')
        else:
            user_bullets.append([f'New users added from {len(users_df['idpname'].unique()):,} different institutions:', users_df['idpname'].unique().tolist()])

    new_user_ids = users_df['id'].unique().tolist()
    metrics_df['details'] = metrics_df['details'].apply(parse_json)
    metrics_df['type'] = metrics_df['details'].str.get('type')
    llm_metrics = metrics_df[metrics_df['type'] == 'llm']
    transcribe_metrics = metrics_df[metrics_df['type'] == 'transcribe']
    synchronify_metrics = metrics_df[metrics_df['type'] == 'synchronify']

    llm_bullets = []
    num_llm_users = len(llm_metrics[~llm_metrics['userId'].isna()]['userId'].unique())
    if len(llm_metrics) == 1:
        llm_bullets.append(f'{len(llm_metrics):,} LLM request made.')
    elif len(llm_metrics) == 0:
        llm_bullets.append(f'{len(llm_metrics):,} LLM requests made.')
    else:
        llm_bullets.append(f'{len(llm_metrics):,} LLM requests made across {num_llm_users:,} different user(s).')
    if len(new_user_ids) > 0:
        llm_new_users = llm_metrics[llm_metrics['userId'].isin(new_user_ids)]
        if len(llm_new_users) > 0:
            last_message = llm_bullets.pop()
            llm_bullets.append([last_message, [f'{len(llm_new_users):,} LLM request(s) made by {len(llm_new_users[~llm_new_users['userId'].isna()]['userId'].unique()):,} new user(s).']])
    llm_metrics['tokens_in'] = llm_metrics['details'].str.get('tokens_in')
    llm_metrics['tokens_out'] = llm_metrics['details'].str.get('tokens_out')
    llm_metrics['open_ai_llm_equiv_cost'] = llm_metrics['details'].str.get('open_ai_llm_equiv_cost')
    tokens_in = llm_metrics['tokens_in'].sum()
    tokens_out = llm_metrics['tokens_out'].sum()
    open_ai_llm_equiv_cost = llm_metrics['open_ai_llm_equiv_cost'].sum()
    llm_bullets.append(f'{int(tokens_in):,} tokens used as input.')
    llm_bullets.append(f'{int(tokens_out):,} tokens generated as output.')
    llm_bullets.append(f'${open_ai_llm_equiv_cost:,.2f} of processing performed based on equivalent OpenAI costs.')

    transcribe_bullets = []
    num_transcribe_users = len(transcribe_metrics[~transcribe_metrics['userId'].isna()]['userId'].unique())
    if len(transcribe_metrics) == 1:
        transcribe_bullets.append(f'{len(transcribe_metrics):,} transcription job ran.')
    elif len(transcribe_metrics) == 0:
        transcribe_bullets.append(f'{len(transcribe_metrics):,} transcription jobs ran.')
    else:
        transcribe_bullets.append(f'{len(transcribe_metrics):,} transcription jobs ran across {num_transcribe_users:,} different user(s).')
    if len(new_user_ids) > 0:
        transcribe_new_users = transcribe_metrics[transcribe_metrics['userId'].isin(new_user_ids)]
        if len(transcribe_new_users) > 0:
            last_message = transcribe_bullets.pop()
            transcribe_bullets.append([last_message, [f'{len(transcribe_new_users):,} transcription job(s) ran by {len(transcribe_new_users[~transcribe_new_users['userId'].isna()]['userId'].unique()):,} new user(s).']])
    transcribe_metrics['minutes_audio'] = transcribe_metrics['details'].str.get('minutes_audio')
    minutes_audio = transcribe_metrics['minutes_audio'].sum()
    transcribe_metrics['open_ai_whisper_equiv_cost'] = transcribe_metrics['details'].str.get('open_ai_whisper_equiv_cost')
    open_ai_llm_equiv_cost = transcribe_metrics['open_ai_whisper_equiv_cost'].sum()
    transcribe_bullets.append(f'{round(minutes_audio, 2):,} total minutes of audio transcribed.')
    transcribe_bullets.append(f'${open_ai_llm_equiv_cost:,.2f} of transcription performed based on equivalent OpenAI costs.')

    # synchronify_bullets = []
    # num_synchronify_users = len(synchronify_metrics[~synchronify_metrics['userId'].isna()]['userId'].unique())
    # if len(synchronify_metrics) == 1:
    #     synchronify_bullets.append(f'{len(synchronify_metrics):,} synchronification job ran.')
    # elif len(synchronify_metrics) == 0:
    #     synchronify_bullets.append(f'{len(synchronify_metrics):,} synchronification jobs ran.')
    # else:
    #     synchronify_bullets.append(f'{len(synchronify_metrics):,} synchronification jobs ran across {num_synchronify_users:,} different user(s).')
    # if len(new_user_ids) > 0:
    #     synchronify_new_users = synchronify_metrics[synchronify_metrics['userId'].isin(new_user_ids)]
    #     if len(synchronify_new_users) > 0:
    #         last_message = synchronify_bullets.pop()
    #         synchronify_bullets.append([last_message, [f'{len(synchronify_new_users):,} synchronification job(s) ran by {len(synchronify_new_users[~synchronify_new_users['user_id'].isna()]['user_id'].unique()):,} new user(s).']])
    # synchronify_metrics['minutes_audio'] = synchronify_metrics['details'].str.get('minutes_audio')
    # minutes_audio = synchronify_metrics['minutes_audio'].sum()
    # synchronify_metrics['open_ai_whisper_equiv_cost'] = synchronify_metrics['details'].str.get('open_ai_whisper_equiv_cost')
    # open_ai_whisper_equiv_cost = synchronify_metrics['open_ai_whisper_equiv_cost'].sum()
    # synchronify_metrics['open_ai_llm_equiv_cost'] = synchronify_metrics['details'].str.get('open_ai_llm_equiv_cost')
    # open_ai_llm_equiv_cost = synchronify_metrics['open_ai_llm_equiv_cost'].sum()
    # synchronify_open_ai = open_ai_whisper_equiv_cost + open_ai_llm_equiv_cost
    # synchronify_metrics['tokens_in'] = synchronify_metrics['details'].str.get('tokens_in')
    # synchronify_metrics['tokens_out'] = synchronify_metrics['details'].str.get('tokens_out')
    # tokens_in = synchronify_metrics['tokens_in'].sum()
    # tokens_out = synchronify_metrics['tokens_out'].sum()
    # synchronify_bullets.append(f'{int(tokens_in):,} tokens used as input for synchronification.')
    # synchronify_bullets.append(f'{int(tokens_out):,} tokens generated as output for synchronification.')
    # synchronify_bullets.append(f'{round(minutes_audio, 2):,} total minutes of audio analyzed for synchronification.')
    # synchronify_bullets.append(f'${synchronify_open_ai:,.2f} of synchronification performed based on equivalent OpenAI costs.')

    content_items = [{'type': 'subheading', 'data': 'New User Data'},
                     {'type': 'bullets', 'data': user_bullets},
                     {'type': 'subheading', 'data': 'LLM Request Data'},
                     {'type': 'bullets', 'data': llm_bullets},
                     {'type': 'subheading', 'data': 'Transcription Data'},
                     {'type': 'bullets', 'data': transcribe_bullets}]


    c = canvas.Canvas(os.path.join(reports_directory, filename), pagesize=letter)
    width, height = letter
    c.setFont('Helvetica-Bold', 16)
    c.drawString(1 * inch, height - 1 * inch, f'CAT-Talk Report- {current_date.strftime('%m/%d/%Y')}')
    c.line(1 * inch, height - 1.1 * inch, width - 1 * inch, height - 1.1 * inch)
    c.setFont('Helvetica', 12)
    c.drawString(1 * inch, height - 1.3 * inch, f'Report generated for CAT-Talk self-service tool using data since {starting_date}.')

    y_position = height - 1.5 * inch
    line_spacing = 0.3 * inch
    subheading_spacing = 0.4 * inch


    def check_page_break(current_y, needed_space):
        """Checks if a page break is needed and creates one if so."""
        if current_y < needed_space:
            c.showPage()
            c.setFont('Helvetica', 12)  # Reset font on new page
            return height - 1 * inch  # Return new y_position
        return current_y


    for item in content_items:
        item_type = item.get('type')
        item_data = item.get('data')

        if item_type == 'subheading':
            # Add space before the subheading
            y_position -= subheading_spacing
            y_position = check_page_break(y_position, 1.5 * inch)

            c.setFont('Helvetica-Bold', 12)
            c.drawString(1.1 * inch, y_position, item_data)
            c.setFont('Helvetica', 12)  # Switch back to regular font
            y_position -= line_spacing  # Add a little space after the subheading

        elif item_type == 'bullets':
            for bullet_item in item_data:
                y_position = check_page_break(y_position, 1 * inch)

                if isinstance(bullet_item, str):
                    # This is a simple, top-level bullet point
                    bullet_text = f"• {bullet_item}"
                    c.drawString(1.2 * inch, y_position, bullet_text)
                    y_position -= line_spacing

                elif isinstance(bullet_item, (list, tuple)) and len(bullet_item) == 2:
                    # This is a main bullet with a list of sub-bullets
                    main_bullet_text = f"• {bullet_item[0]}"
                    sub_bullets = bullet_item[1]

                    c.drawString(1.2 * inch, y_position, main_bullet_text)
                    y_position -= line_spacing

                    # Now, draw the sub-bullets
                    for sub_point in sub_bullets:
                        y_position = check_page_break(y_position, 1 * inch)
                        # Use a different bullet character and indent further
                        sub_bullet_text = f"  - {sub_point}"
                        c.drawString(1.4 * inch, y_position, sub_bullet_text)
                        y_position -= line_spacing


    c.save()
    users_df = users_df.sort_values(by='addedTimestamp')
    llm_metrics = llm_metrics.drop(['details', 'type'], axis=1)
    transcribe_metrics = transcribe_metrics.drop(['details', 'type'], axis=1)
    # actions_df = actions_df.drop(['uuid'], axis=1).sort_values(by='time_performed')
    with pd.ExcelWriter(os.path.join(reports_directory, filename[:-4]+'.xlsx'), engine='xlsxwriter') as writer: #Save results to excel file
        users_df.to_excel(writer, sheet_name="New User Data", index=False)
        llm_metrics.to_excel(writer, sheet_name="New LLM Request Data", index=False)
        transcribe_metrics.to_excel(writer, sheet_name="New Transcription Data", index=False)

