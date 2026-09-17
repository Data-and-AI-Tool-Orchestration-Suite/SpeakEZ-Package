import sys
from openai import OpenAI
import configparser
import json
import os

config = configparser.ConfigParser()
config.read('config.ini')
llm_api_key = config.get('LLM', 'openai_api_key')
openai_api_base= config.get('LLM', 'openai_api_base')


llm = OpenAI(
    api_key=llm_api_key,
    base_url=openai_api_base,
)

content_detection_schema = {
    "type": "object",
        "description": "Find and print a small (at most one sentence) excerpt of sensitive content",
        "properties": {
            "is_sensitive": {
                "type": "boolean",
                "description": "Whether sensitive content is present or not"
            },
            "text_excerpt": {
                "type": "string",
                "description": "A small excerpt (at most one sentence) from the text with sensitive content."
            },
            "description": {
                "type": "string",
                "description": "Why the content is considered sensitive."
            }
        },
        "required": ["is_sensitive", "text_excerpt", "description"],
        "additionalProperties": False
    }

response_format = {
    "type": "json_object",
    "schema":  content_detection_schema,
}

def content_query(lines):
    formatted_lines = []
    for line in lines:
        formatted_lines.append(line.split('\t')[-1]) # remove line numbers and time stamps
    
    messages = []
    messages.append({"role": "system", "content": "Report line numbers and print sensitive content relating to abuse, drugs, scandal, or sexuality"})
    messages.append({"role": "user", "content": formatted_lines})

    
    # print(formatted_lines)
    # Making the completion request to OpenAI API without function_call
    response = llm.chat.completions.create(
        model="",
        messages=messages,
        max_tokens=700,
        response_format=response_format
    )

    # Manually add in the line numbers    
    line_nums = ''
    for line in lines:
        line_nums = line_nums + line.split()[0] + ','
    line_nums = line_nums[0:-1]

    # Attempt to load the response into json
    try:
        formatted_response = json.loads(response.choices[0].message.content)
        formatted_response['line_nums'] = line_nums
        return formatted_response
    except:
        return {'is_sensitive': False, 'content': 'error detected', 'line_nums': line_nums}


if __name__ == '__main__':
    transcript_path = sys.argv[1]
    content_dict = {}
    group_of_lines = []
    num_lines_to_process = 16 # number of lines to send to the LLM at one time

    with open(transcript_path, 'r') as transcript_file:
        transcript = transcript_file.readlines()
    for line in transcript:
        group_of_lines.append(line)
        if len(group_of_lines) == num_lines_to_process: # Grab lines to send
            response = content_query(group_of_lines) # send those lines
            if (response['is_sensitive']): # if content is sensitive, add to dict
                content_dict[response['line_nums']] = {'text_excerpt': response['text_excerpt'], 'description': response['description']} 
            group_of_lines = []  # clear

    filename, _ = os.path.splitext(transcript_path)
    new_file = filename + '_riskalyze.json'

    with open(new_file, 'w') as json_file:
        json.dump(content_dict, json_file, indent=2)