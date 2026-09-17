import sys
from openai import OpenAI
import configparser
import json
import os

config = configparser.ConfigParser()
config.read('config.ini')
llm_api_key = config.get('LLM', 'openai_api_key')
openai_api_base= config.get('LLM', 'openai_api_base')

max_tokens_content = 500 # max tokens for the initial summarizing
max_tokens_final = 3000 # max tokens for the final summary

llm = OpenAI(
    api_key=llm_api_key,
    base_url=openai_api_base,
)

# tokenizer = AutoTokenizer.from_pretrained("meta-llama/Meta-Llama-3-8B")

summary_scheme = {
    "type": "object",
    "description": "Summarize this text and list keywords, locations, important people",
    "properties": {
        "description": {
            "type": "string",
            "description": "A description of the text."
        },
        "keywords": {
            "type": "string",
            "description": "List of important keywords from the description of the text."
        },
        "locations": {
            "type": "string",
            "description": "List of locations mentioned in the text."
        },
        "people": {
            "type": "string",
            "description": "List of people mentioned in the text."
        }
    },
    "required": ["description", "keywords", "locations", "people"],
    "additionalProperties": False
}

response_format = {
    "type": "json_object",
    "schema":  summary_scheme,
}

final_summary_scheme = {
    "type": "object",
    "description": "Looking at the summaries, keywords, and people, create a list of chapters this transcript would have. Do larger chapters, then subchapters within. In addition, create a separate list of keywords, locations, and people involved",
    "properties": {
        "chapters": {
            "type": "string",
            "description": "A description of the text."
        },
        "keywords": {
            "type": "string",
            "description": "List of important keywords from the description of the text."
        },
        "locations": {
            "type": "string",
            "description": "List of locations mentioned in the text."
        },
        "people": {
            "type": "string",
            "description": "List of people mentioned in the text."
        }
    },
    "required": ["description", "keywords", "locations", "people"],
    "additionalProperties": False
}

final_response_format = {
    "type": "json_object",
    "schema":  summary_scheme,
}


def content_query(lines):
    formatted_lines = []
    for line in lines:
        formatted_lines.append(line.split('\t')[-1]) # remove line numbers and time stamps
    
    messages = []
    messages.append({"role": "system", "content": "Find the major theme of this passage along with keywords, locations, and people mentioned"})
    messages.append({"role": "user", "content": formatted_lines})

    
    # print(formatted_lines)
    # Making the completion request to OpenAI API without function_call
    
    print(messages)

    # Manually add in the line numbers    
    line_nums = []
    for line in lines:
        line_nums.append(line.split()[0])
    
    try:
        response = llm.chat.completions.create(
            model="",
            messages=messages,
            max_tokens=max_tokens_content,
            response_format=response_format
        )
        
        

        # Attempt to load the response into json
        formatted_response = json.loads(response.choices[0].message.content)
        formatted_response['line_nums'] = ",".join(map(str, line_nums))
        print(formatted_response['line_nums'])
        return formatted_response
    except:
        return {'description': '', 'keywords': '', 'locations': '', 'people': '', 'line_nums': ",".join(map(str, line_nums))}

def final_query(summaries):
    
    #outputs[response['line_nums']] = {'description': response['description'], 'keywords': response['keywords'], 'people':response['people']} 


    messages = []
    messages.append({"role": "system", "content": ""})
    messages.append({"role": "user", "content": f'I summarized a transcript some number of lines at a time. Looking at the summaries, keywords, and people, create a list of chapters this transcript would have. Do larger chapters, then subchapters within. In addition, create a separate list of keywords, locations, and people involved: {json.dumps(summaries)}'})

    print(f'FINAL MESSAGE SENT:\n\n{messages}')
    
    response = llm.chat.completions.create(
        model="",
        messages=messages,
        max_tokens=max_tokens_final,
    )

    return response




if __name__ == '__main__':
    transcript_path = sys.argv[1]
    content_dict = {}
    group_of_lines = []
    num_lines_to_process = 8 # number of lines to send to the LLM at one time
    outputs = {}

    with open(transcript_path, 'r') as transcript_file:
        transcript = transcript_file.readlines()
    for i in range(len(transcript)):
        group_of_lines.append(transcript[i])
        if len(group_of_lines) == num_lines_to_process: # Grab lines to send
            response = content_query(group_of_lines) # send those lines
            outputs[response['line_nums']] = {'description': response['description'], 'keywords': response['keywords'], 'people':response['people']} 
            print(response, end='\n\n\n\n')
            group_of_lines = []  # clear

    print(f'RESPONSE:\n\n{final_query(outputs)}')
    

    # filename, _ = os.path.splitext(transcript_path)
    # new_file = filename + '_riskalyze.json'

    # with open(new_file, 'w') as json_file:
    #     json.dump(content_dict, json_file, indent=2)