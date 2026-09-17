import os
import json
import requests
import xml.etree.ElementTree as ET
from typing import Dict, Any, List, Optional
import xml.dom.minidom
from openai import OpenAI
import configparser
import logging
import argparse
from pathlib import Path
from time import sleep
import re
import ast
from clearml.utilities import pyhocon
from os.path import isfile, join
import botocore # type: ignore
import spacy
import subprocess
import sys
import re
from collections import Counter
from nltk.corpus import stopwords
from nltk.tokenize import word_tokenize
import nltk
from collections import defaultdict
from time import sleep
from json import JSONDecodeError

# --- Constants ---

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

config = configparser.ConfigParser()
config.read('/workspace/config.ini')
base_url = config.get('LLM', 'openai_api_base')
api_key = config.get('LLM', 'openai_api_key')
updates_api_key = config.get('API Server', 'updates_api_key')
callback_url = config.get('API Server', 'callback_url')
cat_talk_callback_url = config.get('API Server', 'callback_url_2')

DEFAULT_LARGE_CONTEXT_MODEL = config.get('Model', 'large_model')
DEFAULT_TOOL_CAPABLE_MODEL = config.get('Model', 'large_model')
# DEFAULT_TOOL_CAPABLE_MODEL = "DeepSeek-R1"
MAX_LLM_RETRIES = 3
RETRY_DELAY_SECONDS = 5

llm_to_openai = {
    DEFAULT_LARGE_CONTEXT_MODEL: "gpt-4o",
    DEFAULT_TOOL_CAPABLE_MODEL: "gpt-3.5-turbo"
}


client = OpenAI(
    api_key=api_key,
    base_url=base_url,
)

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
        'Content-Type': 'application/json'  # Ensure the content type is JSON  
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



tools = [
    {
        "type": "function",
        "function": {
            "name": "save_keywords",
            "description": "Save a list of keywords extracted from the input text. The keywords should be relevant to the content and can be used for indexing or search purposes.",
            "parameters": {
                "type": "object",
                "properties": {
                    "keywords": {"type": "array", "description": "A list of keywords extracted from the text.", "items": {"type": "string"}},
                },
                "required": ["keywords"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    {
        "type": "function",
        "function": {
            "name": "extract_date",
            "description": "Extract the interview date in ISO format (YYYY-MM-DD) from the input. The date may appear as a line like 'Date: 2013-07-04', 'Interview Date: July 4, 2013', or within a <date> XML tag or similar. Return a string in ISO format (YYYY-MM-DD).",
            "parameters": {
                "type": "object",
                "properties": {
                    "date": {"type": "string", "description": "The interview date in ISO format (YYYY-MM-DD)."},
                },
                "required": ["date"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    {
        "type": "function",
        "function": {
            "name": "extract_title",
            "description": "Extract the interview title from the document. The title may appear as a line like 'Title: Interview with Steve Zahn', 'Interview Title: ...', or within a <title> XML tag or similar. Return a string.",
            "parameters": {
                "type": "object",
                "properties": {
                    "title": {"type": "string", "description": "The interview title."},
                },
                "required": ["title"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    {
        "type": "function",
        "function": {
            "name": "extract_duration",
            "description": "Extract the duration of the interview in HH:MM:SS format. The duration may appear as a line like 'Duration: 01:17:44', 'Length: 1 hour 17 minutes 44 seconds', or within a <duration> XML tag or similar. Return a string in HH:MM:SS format.",
            "parameters": {
                "type": "object",
                "properties": {
                    "duration": {"type": "string", "description": "The duration in HH:MM:SS format."},
                },
                "required": ["duration"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    {
        "type": "function",
        "function": {
            "name": "extract_interviewer",
            "description": "Extract the interviewer(s) as a list of names. Interviewer names may appear as a line like 'Interviewer: Doug Boyd', 'Interviewers: Doug Boyd, Jane Smith', or within <interviewer> XML tags or similar. Return a list of names.",
            "parameters": {
                "type": "object",
                "properties": {
                    "interviewers": {"type": "array", "items": {"type": "string"}, "description": "List of interviewer names."},
                },
                "required": ["interviewers"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    {
        "type": "function",
        "function": {
            "name": "extract_interviewee",
            "description": "Extract the interviewee(s) as a list of names. Interviewee names may appear as a line like 'Interviewee: Steve Zahn', 'Interviewees: Steve Zahn, John Doe', or within <interviewee> XML tags or similar. Return a list of names.",
            "parameters": {
                "type": "object",
                "properties": {
                    "interviewees": {"type": "array", "items": {"type": "string"}, "description": "List of interviewee names."},
                },
                "required": ["interviewees"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    # --- ADDED extract_points_text TOOL ---
    {
        "type": "function",
        "function": {
            "name": "save_extract_points_text",
            "description": (
                "Save extracted raw text for each index point from the input. "
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "points_text": {
                        "type": "array",
                        "items": {"type": "string"},
                        "description": "List of raw text blocks for each point."
                    }
                },
                "required": ["points_text"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    # --- END extract_points_text TOOL ---
    {
        "type": "function",
        "function": {
            "name": "extract_points",
            "description": (
                "Given the raw text for a single index point, extract the required fields: time, title, partial_transcript, synopsis, keywords, and subjects. "
                "All other fields (title_alt, partial_transcript_alt, synopsis_alt, keywords_alt, subjects_alt, locations, hyperlinks, etc.) are OPTIONAL. "
                "If any required field is missing, use a clear placeholder. Return a single point object. "
                "For the 'locations' field, ALWAYS return a list of objects (not a string), where each object has: 'location' (coordinates or place name), 'location_text' (optional), 'location_text_alt' (optional), and 'location_zoom' (optional). If no locations, return an empty list."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "point": {
                        "type": "object",
                        "properties": {
                            "time": {"type": "string", "description": "Time for the index point (REQUIRED)."},
                            "title": {"type": "string", "description": "Title for the index point (REQUIRED)."},
                            "partial_transcript": {"type": "string", "description": "Partial transcript for the index point (REQUIRED)."},
                            "synopsis": {"type": "string", "description": "Synopsis for the index point (REQUIRED)."},
                            "keywords": {"type": "string", "description": "Keywords for the index point (REQUIRED)."},
                            "subjects": {"type": "string", "description": "Subjects for the index point (REQUIRED)."},
                            "title_alt": {"type": "string", "description": "Alternative title (OPTIONAL)."},
                            "partial_transcript_alt": {"type": "string", "description": "Alternative partial transcript (OPTIONAL)."},
                            "synopsis_alt": {"type": "string", "description": "Alternative synopsis (OPTIONAL)."},
                            "keywords_alt": {"type": "string", "description": "Alternative keywords (OPTIONAL)."},
                            "subjects_alt": {"type": "string", "description": "Alternative subjects (OPTIONAL)."},
                            "locations": {
                                "type": "array",
                                "items": {
                                    "type": "object",
                                    "properties": {
                                        "location": {"type": "string", "description": "Coordinates (lat,lon) or place name."},
                                        "location_text": {"type": "string", "description": "Human-readable place name (OPTIONAL)."},
                                        "location_text_alt": {"type": "string", "description": "Alternative place name (OPTIONAL)."},
                                        "location_zoom": {"type": "string", "description": "Map zoom level (OPTIONAL)."},
                                    },
                                    "required": ["location"],
                                    "additionalProperties": True
                                },
                                "description": "List of location objects for this point. Each must have at least 'location'."
                            },
                            "hyperlinks": {"type": "string", "description": "Hyperlinks (OPTIONAL)."},
                        },
                        "required": ["time", "title", "partial_transcript", "synopsis", "keywords", "subjects"],
                        "additionalProperties": True
                    }
                },
                "required": ["point"],
                "additionalProperties": False
            },
            "strict": True
        }
    },
    {
        "type": "function",
        "function": {
            "name": "extract_language",
            "description": "Extract the language of the transcript. The language may appear as a line like 'Language: English', 'Transcript Language: Spanish', or within a <language> XML tag or similar. Return a string with the language name (e.g., 'English', 'Spanish').",
            "parameters": {
                "type": "object",
                "properties": {
                    "language": {"type": "string", "description": "The language of the transcript (e.g., 'English', 'Spanish')."},
                },
                "required": ["language"],
                "additionalProperties": False
            },
            "strict": True
        }
    }
]

class TranscriptProcessor:
    def __init__(self, large_context_api_key: str, tool_capable_api_key: str):
        """
        Initialize the TranscriptProcessor with API keys for both LLMs
        Args:
            large_context_api_key: API key for the large context LLM
            tool_capable_api_key: API key for the tool-capable LLM
        """
        self.large_context_api_key = large_context_api_key
        self.tool_capable_api_key = tool_capable_api_key

    def load_transcript(self, file_path: str) -> str:
        """
        Load the interview transcript from a file
        Args:
            file_path: Path to the transcript file
        Returns:
            The transcript content as a string
        """
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                return f.read()
        except Exception as e:
            logging.error(f"Failed to load transcript from {file_path}: {e}")
            raise

    def generate_initial_report(self, transcript: str, schema_path: str = None) -> str:
        """
        Use the large context LLM to generate a structured, tool-call-friendly pseudo-XML/JSON document from the transcript.
        Args:
            transcript: The interview transcript content
            schema_path: Optional path to XML schema file to include in the prompt
        Returns:
            The structured pseudo-XML/JSON from the large context LLM
        """
        schema_content = ""
        if schema_path and Path(schema_path).exists():
            try:
                with open(schema_path, 'r', encoding='utf-8') as f:
                    schema_content = f.read()
            except Exception as e:
                logging.warning(f"Could not read schema file {schema_path}: {e}")

        prompt = f"""
        You are an expert in oral history metadata extraction. Given the following interview transcript, generate a structured document (NOT XML) that contains all the information needed for OHMS XML construction, in a format that matches the fields and structure expected by the extraction tools below.

        The document should be a plain text, indented, human-readable structure (like YAML, JSON, or pseudo-XML) with the following top-level fields:
        - date: The interview date (e.g., 2013-07-04)
        - title: The interview title (e.g., Interview with Steve Zahn)
        - duration: The duration in HH:MM:SS (e.g., 01:17:44)
        - interviewers: List of interviewer names
        - interviewees: List of interviewee names
        - points: List of index points, each with:
            - time: Time in [hh:mm:ss] format (e.g., [01:25:45])
            - title: Title for the point
            - partial_transcript: Partial transcript for the point
            - synopsis: Synopsis for the point (MUST BE at least a 3 sentence summary)
            - keywords: Keywords for the point
            - subjects: Subjects for the point
            - (optional) title_alt, partial_transcript_alt, synopsis_alt, keywords_alt, subjects_alt, gpspoints, hyperlinks, etc.
            - (optional) locations: Significant locations related to the narrative
        
        The structure should be easy for a tool to parse and should not contain any XML tags or angle brackets. Do NOT generate XML. Do NOT use double-serialized JSON. Do NOT include any markup. Use clear field names and plain text values. If a field is missing, use a clear placeholder (e.g., 'REQUIRED').

        Additionally, be very granular with the index points. More index points is better if possible.
        
        Here is the transcript:
        {transcript}
        """
        response = self._call_large_context_llm(prompt)
        return response

    def _call_large_context_llm(self, prompt: str) -> str:
        """
        Call the large context LLM API with retry logic and streaming timeout/guard.
        Args:
            prompt: The prompt to send to the LLM
        Returns:
            The LLM's response
        """
        MAX_CHUNKS = 10000  # Prevent infinite streaming
        for attempt in range(1, MAX_LLM_RETRIES + 1):
            try:
                response = client.chat.completions.create(
                    model=DEFAULT_LARGE_CONTEXT_MODEL,
                    messages=[{"role": "user", "content": prompt}],
                    max_tokens=10000,
                    stream=True,
                    timeout=120,  # 2 minute timeout for the request
                )
                query_response = ""
                chunk_count = 0
                for chunk in response:
                    chunk_count += 1
                    content = getattr(chunk.choices[0].delta, "content", None)
                    if content is not None:
                        print(content, end="", flush=True)
                        query_response += content
                    if chunk_count > MAX_CHUNKS:
                        logging.error("Streaming exceeded maximum allowed chunks. Aborting.")
                        break
                print("\n")
                global tokens_in, tokens_out
                tokens_in += count_tokens(prompt, model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
                logging.info(f"Total tokens in: {tokens_in}")
                tokens_out += count_tokens(query_response, model=llm_to_openai[DEFAULT_LARGE_CONTEXT_MODEL])
                logging.info(f"Total tokens out: {tokens_out}")
                if not query_response:
                    logging.warning("No response received from LLM.")
                return query_response.split("</think>")[-1].strip()
            except Exception as e:
                # Always log prompt details for any LLM call error
                logging.error(f"Attempt {attempt}: Error during LLM call: {e}")
                logging.error(f"Prompt length: {len(prompt)}")
                logging.error(f"Prompt contents (first 500 chars): {prompt[:500]}")
                # Check for OpenAI BadRequestError due to context length
                if (
                    hasattr(e, 'message') and 'maximum context length' in str(e)
                ) or (
                    hasattr(e, 'args') and any('maximum context length' in str(arg) for arg in e.args)
                ):
                    logging.warning("Context length exceeded, chunking transcript and merging outputs.")
                    # Try to extract transcript from prompt
                    import re
                    transcript_match = re.search(r"Here is the transcript:\n(.+)", prompt, re.DOTALL)
                    if transcript_match:
                        transcript = transcript_match.group(1)
                    else:
                        transcript = prompt
                    # Chunk transcript into ~10000 token pieces (approx 8000 words)
                    words = transcript.split()
                    chunk_size = 8000
                    chunks = [" ".join(words[i:i+chunk_size]) for i in range(0, len(words), chunk_size)]
                    merged_output = ""
                    for idx, chunk in enumerate(chunks):
                        chunk_prompt = re.sub(r"Here is the transcript:\n.+", f"Here is the transcript:\n{chunk}", prompt, flags=re.DOTALL)
                        logging.info(f"Processing chunk {idx+1}/{len(chunks)}")
                        try:
                            chunk_response = self._call_large_context_llm(chunk_prompt)
                        except Exception as ce:
                            logging.error(f"Error in chunk {idx+1}: {ce}")
                            logging.error(f"Chunk index: {idx+1}, chunk length: {len(chunk)}")
                            logging.error(f"Chunk contents (first 500 chars): {chunk}")
                            chunk_response = ""
                        merged_output += chunk_response + "\n"
                    return merged_output.strip()
                if attempt < MAX_LLM_RETRIES:
                    sleep(RETRY_DELAY_SECONDS)
                else:
                    raise

    def extract_keywords(self, text: str) -> List[str]:
        results = self._call_tool_capable_llm(prompt=f"Extract relevant keywords from the following text:\n{text}")
        return results.get("keywords", [])

        
    def build_xml(self, data: dict, og_filename: str) -> str:
        """Construct XML document from collected data, inserting dummy values for any missing fields, and ensure schema compliance."""
        from lxml import etree as LET
        def dummy(field):
            # Schema-compliant dummy values for OHMS XML
            field = field.lower()
            # Enumerations from schema
            host_enum = "Avalon"
            clip_format_enum = "audio"
            fmt_enum = "audio"
            # Integer fields (must be string for XML text except for attributes)
            int_fields = {"time", "gps_zoom", "location_zoom"}
            # Boolean/integer fields (translate, userestrict)
            bool_int_fields = {"translate", "userestrict"}
            # Date fields
            date_fields = {"date", "record_dt"}
            # List fields (return a single string for dummy)
            list_fields = {"keywords", "subjects", "interviewers", "interviewees", "format"}
            # Special format fields
            special_fields = {
                "version": "6.0",  # decimal
                "date": "2000-01-01",
                "record_dt": "2000-01-01",
                "duration": "00:00:00",
                "file_name": "file.mp3",
                "xmlfilename": "file.xml",
                "xmllocation": "location",
                "media_url": "http://example.com/media.mp3",
                "collection_link": "http://example.com/collection",
                "series_link": "http://example.com/series",
                "repository_url": "http://example.com/repository",
                "rights": "All rights reserved",
                "type": "interview",
                "fmt": fmt_enum,
                "accession": "A0001",
                "cms_record_id": "CMS0001",
                "vtt_transcript": "WEBVTT transcript",
                "vtt_transcript_alt": "WEBVTT transcript alt",
                "transcript": "Transcript text",
                "transcript_alt": "Transcript alt text",
                "partial_transcript": "Partial transcript",
                "synopsis": "Synopsis text",
                "title": "Interview Title",
                "title_alt": "Alternate Title",
                "collection_id": "C0001",
                "collection_name": "Collection Name",
                "series_id": "S0001",
                "series_name": "Series Name",
                "repository": "Repository Name",
                "funding": "Funding Source",
                "kembed": "kembed",
                "language": "en",
                "user_notes": "Notes",
                "sync": "sync",
                "sync_alt": "sync_alt",
                "mediafile": "mediafile",
                "gps": "37.0000,-84.0000",
                "gps_text": "Location Name",
                "gps_text_alt": "Alternate Location Name",
                "hyperlink": "http://example.com",
                "hyperlink_text": "Hyperlink Text",
                "hyperlink_text_alt": "Hyperlink Text Alt",
                "host": host_enum,
                "clip_format": clip_format_enum,
                "media_id": "M0001",  # string per schema
            }
            if field in special_fields:
                return special_fields[field]
            if field in int_fields:
                return "0"
            if field in bool_int_fields:
                return "0"
            if field in date_fields:
                return "2000-01-01"
            if field in list_fields:
                return "string"
            # Default: string
            return "string"
        NS = "https://www.weareavp.com/nunncenter/ohms"
        NSMAP = {None: NS, 'xsi': "http://www.w3.org/2001/XMLSchema-instance"}
        SCHEMA_LOC = f"{NS} ohms.xsd"
        # Root element with namespace and schema location
        root = LET.Element("ROOT", nsmap=NSMAP)
        root.set("{http://www.w3.org/2001/XMLSchema-instance}schemaLocation", SCHEMA_LOC)

        # record (add id and dt attributes as required by schema)
        # id: should be a number (stringified for XML), dt: should be a date string (YYYY-MM-DD)
        record_id = data.get("record_id", dummy("id"))
        # Try to extract a number from record_id, fallback to dummy if not possible
        try:
            record_id_num = int(str(record_id))
        except Exception:
            record_id_num = 1234  # fallback dummy
        record_id_str = str(record_id_num)

        record_dt = data.get("record_dt", dummy("dt"))
        # Validate date format (YYYY-MM-DD), fallback to dummy if not valid
        import re
        if not isinstance(record_dt, str) or not re.match(r"^\\d{4}-\\d{2}-\\d{2}$", record_dt):
            record_dt = "1999-12-31"
        record = LET.SubElement(root, "record", id=record_id_str, dt=record_dt)

        # version (required)
        LET.SubElement(record, "version").text = str(data.get("version", dummy("version")))
        # date (required, with value/format attributes)
        date_val = data.get("date", dummy("date"))
        date_elem = LET.SubElement(record, "date")
        date_elem.set("value", date_val)
        date_elem.set("format", "yyyy-mm-dd")
        # date_nonpreferred_format (optional)
        if data.get("date_nonpreferred_format"):
            LET.SubElement(record, "date_nonpreferred_format").text = data["date_nonpreferred_format"]
        # cms_record_id (optional)
        LET.SubElement(record, "cms_record_id").text = data.get("cms_record_id", dummy("cms_record_id"))
        # title (required)
        LET.SubElement(record, "title").text = og_filename
        # accession (optional)
        LET.SubElement(record, "accession").text = data.get("accession", dummy("accession"))
        # duration (optional)
        LET.SubElement(record, "duration").text = data.get("duration", dummy("duration"))
        # collection_id (optional)
        LET.SubElement(record, "collection_id").text = data.get("collection_id", dummy("collection_id"))
        # collection_name (optional)
        LET.SubElement(record, "collection_name").text = data.get("collection_name", dummy("collection_name"))
        # series_id (optional)
        LET.SubElement(record, "series_id").text = data.get("series_id", dummy("series_id"))
        # series_name (optional)
        LET.SubElement(record, "series_name").text = data.get("series_name", dummy("series_name"))
        # repository (optional)
        LET.SubElement(record, "repository").text = data.get("repository", dummy("repository"))
        # funding (optional)
        LET.SubElement(record, "funding").text = data.get("funding", dummy("funding"))
        # repository_url (optional)
        LET.SubElement(record, "repository_url").text = data.get("repository_url", dummy("repository_url"))
        # subject (repeatable)
        for subject in data.get("subjects", [dummy("subject")]):
            LET.SubElement(record, "subject").text = subject
        # keyword (repeatable)
        for keyword in data.get("keywords", [dummy("keyword")]):
            LET.SubElement(record, "keyword").text = keyword
        # interviewee (repeatable)
        interviewees = data.get("interviewees", [dummy("interviewee")])
        # Normalize: handle string, or list with a single string that looks like a list
        if isinstance(interviewees, str):
            try:
                parsed = ast.literal_eval(interviewees)
                if isinstance(parsed, list):
                    interviewees = parsed
                else:
                    interviewees = [interviewees]
            except Exception:
                interviewees = [interviewees]
        elif isinstance(interviewees, list) and len(interviewees) == 1 and isinstance(interviewees[0], str):
            s = interviewees[0].strip()
            if (s.startswith("[") and s.endswith("]")) or (s.startswith("['") and s.endswith("']")):
                try:
                    parsed = ast.literal_eval(s)
                    if isinstance(parsed, list):
                        interviewees = parsed
                except Exception:
                    pass
        for interviewee in interviewees:
            LET.SubElement(record, "interviewee").text = interviewee
        # interviewer (repeatable)
        interviewers = data.get("interviewers", [dummy("interviewer")])
        if isinstance(interviewers, str):
            try:
                parsed = ast.literal_eval(interviewers)
                if isinstance(parsed, list):
                    interviewers = parsed
                else:
                    interviewers = [interviewers]
            except Exception:
                interviewers = [interviewers]
        elif isinstance(interviewers, list) and len(interviewers) == 1 and isinstance(interviewers[0], str):
            s = interviewers[0].strip()
            if (s.startswith("[") and s.endswith("]")) or (s.startswith("['") and s.endswith("']")):
                try:
                    parsed = ast.literal_eval(s)
                    if isinstance(parsed, list):
                        interviewers = parsed
                except Exception:
                    pass
        for interviewer in interviewers:
            LET.SubElement(record, "interviewer").text = interviewer
        # format (repeatable)
        for fmt in data.get("format", [dummy("format")]):
            LET.SubElement(record, "format").text = fmt
        # file_name (optional)
        LET.SubElement(record, "file_name").text = data.get("file_name", dummy("file_name"))
        # sync (optional)
        LET.SubElement(record, "sync").text = data.get("sync", dummy("sync"))
        # sync_alt (optional)
        LET.SubElement(record, "sync_alt").text = data.get("sync_alt", dummy("sync_alt"))
        # transcript_alt_lang (optional)
        LET.SubElement(record, "transcript_alt_lang").text = data.get("transcript_alt_lang", dummy("transcript_alt_lang"))
        # translate (optional)
        LET.SubElement(record, "translate").text = str(data.get("translate", dummy("translate")))
        # media_id (optional)
        LET.SubElement(record, "media_id").text = str(data.get("media_id", dummy("media_id")))
        # media_url (optional)
        LET.SubElement(record, "media_url").text = data.get("media_url", dummy("media_url"))
        # mediafile (optional, with nested fields if present)
        if data.get("mediafile"):
            mediafile_elem = LET.SubElement(record, "mediafile")
            for field in ["host", "avalon_target_domain", "host_account_id", "host_player_id", "host_clip_id", "clip_format"]:
                LET.SubElement(mediafile_elem, field).text = data["mediafile"].get(field, dummy(field))
        # kembed (optional)
        LET.SubElement(record, "kembed").text = data.get("kembed", dummy("kembed"))
        # language (optional)
        LET.SubElement(record, "language").text = data.get("language", dummy("language"))
        # user_notes (optional)
        LET.SubElement(record, "user_notes").text = data.get("user_notes", dummy("user_notes"))
        # index (required, with points)
        index_elem = LET.SubElement(record, "index")
        points = data.get("points", [
            {"time": dummy("time"), "title": dummy("title"), "partial_transcript": dummy("partial_transcript"), "synopsis": dummy("synopsis"), "keywords": dummy("keywords"), "subjects": dummy("subjects")}
        ])
        # Robustly handle points as stringified JSON
        if isinstance(points, str):
            try:
                points = json.loads(points)
            except Exception as e:
                logging.error(f"Failed to parse points string as JSON in build_xml: {e}")
                points = []
        # If still a list of strings, try to parse each as a dict
        if isinstance(points, list) and points and isinstance(points[0], str):
            parsed_points = []
            for p in points:
                try:
                    p_fixed = p.replace("'", '"')
                    p_fixed = re.sub(r'([,{]\s*)([a-zA-Z0-9_]+)\s*:', r'\1"\2":', p_fixed)
                    parsed_points.append(json.loads(p_fixed))
                except Exception as e:
                    logging.error(f"Failed to parse pseudo-JSON point in build_xml: {e}\nRaw: {p}")
            points = parsed_points
        # Collect for CSV/TXT summary
        all_point_keywords = []
        all_point_locations = []
        all_point_synopses = []
        def hms_to_seconds(hms):
            # Accepts [hh:mm:ss], [hh:mm:ss.xx], with or without brackets
            import re
            hms = hms.strip()
            if hms.startswith('[') and hms.endswith(']'):
                hms = hms[1:-1]
            match = re.match(r"^(\d{2}):(\d{2}):(\d{2})(?:[.,](\d{1,3}))?$", hms)
            if not match:
                try:
                    return float(hms)
                except Exception:
                    return 0.0
            h, m, s, ms = match.groups()
            total = int(h) * 3600 + int(m) * 60 + int(s)
            if ms:
                total += float('0.' + ms)
            return total

        for point in points:
            point_elem = LET.SubElement(index_elem, "point")
            # Required fields
            for field in ["time", "title", "partial_transcript", "synopsis", "keywords", "subjects"]:
                val = point.get(field, dummy(field))
                # If field is time, convert [hh:mm:ss] or [hh:mm:ss.xx] to seconds
                if field == "time" and isinstance(val, str):
                    val = hms_to_seconds(val)
                if field == "time":
                    # Always cast to int for <time> tag
                    try:
                        val = int(float(val))
                    except Exception:
                        val = 0
                if isinstance(val, list):
                    val = "; ".join(str(v) for v in val)
                # Always convert to string for XML text
                LET.SubElement(point_elem, field).text = str(val)
            # Collect for summary
            if point.get("keywords"):
                if isinstance(point["keywords"], list):
                    all_point_keywords.extend(point["keywords"])
                else:
                    all_point_keywords.extend([k.strip() for k in str(point["keywords"]).split(",")])
            if point.get("locations"):
                locs = point["locations"]
                if not isinstance(locs, list):
                    locs = [locs]
                for loc in locs:
                    if isinstance(loc, dict):
                        place = loc.get("location_text") or loc.get("location") or ""
                        gps = self.geocode_location(place)
                        all_point_locations.append({"place": place, "gps": gps})
                    else:
                        gps = self.geocode_location(str(loc))
                        all_point_locations.append({"place": str(loc), "gps": gps})
            if point.get("synopsis"):
                all_point_synopses.append(str(point["synopsis"]))
            # Optional alt fields
            for field in ["title_alt", "partial_transcript_alt", "synopsis_alt", "keywords_alt", "subjects_alt"]:
                if point.get(field):
                    val = point[field]
                    if isinstance(val, list):
                        val = "; ".join(str(v) for v in val)
                    LET.SubElement(point_elem, field).text = val
            # locations (optional, map to gpspoints for schema compliance)
            if point.get("locations"):
                locations = point["locations"]
                if not isinstance(locations, list):
                    locations = [locations]
                for loc in locations:
                    print(f"loc: {loc}")
                    if isinstance(loc, dict):
                        print_location = loc.get('location', '')
                        gpspoints_elem = LET.SubElement(point_elem, "gpspoints")
                        location_val = loc.get("location", dummy("gps"))
                        if re.match(r"^-?\d+\.\d+,-?\d+\.\d+$", location_val.strip()):
                            LET.SubElement(gpspoints_elem, "gps").text = location_val
                        else:
                            coords = self.geocode_location(location_val) if location_val else None
                            if coords:
                                LET.SubElement(gpspoints_elem, "gps").text = coords
                            else:
                                LET.SubElement(gpspoints_elem, "gps").text = dummy("gps")
                        LET.SubElement(gpspoints_elem, "gps_zoom").text = "0"
                        if location_val:
                            LET.SubElement(gpspoints_elem, "gps_text").text = print_location
                        if loc.get("location_text_alt"):
                            LET.SubElement(gpspoints_elem, "gps_text_alt").text = loc["location_text_alt"]
                    else:
                        # If just a string, treat as gps_text
                        print_location = str(loc)
                        gpspoints_elem = LET.SubElement(point_elem, "gpspoints")
                        coords = self.geocode_location(print_location)
                        if coords:
                            LET.SubElement(gpspoints_elem, "gps").text = coords
                        else:
                            LET.SubElement(gpspoints_elem, "gps").text = dummy("gps")
                        LET.SubElement(gpspoints_elem, "gps_zoom").text = "0"
                        LET.SubElement(gpspoints_elem, "gps_text").text = print_location
            # hyperlinks (optional, can be nested)
            if point.get("hyperlinks"):
                hyperlinks_elem = LET.SubElement(point_elem, "hyperlinks")
                hyperlinks = point["hyperlinks"]
                if isinstance(hyperlinks, list):
                    for link in hyperlinks:
                        link_elem = LET.SubElement(hyperlinks_elem, "hyperlink")
                        link_val = link.get("hyperlink", dummy("hyperlink")) if isinstance(link, dict) else link
                        if isinstance(link_val, list):
                            link_val = "; ".join(str(v) for v in link_val)
                        link_elem.text = link_val
                        if isinstance(link, dict):
                            if link.get("hyperlink_text"):
                                LET.SubElement(link_elem, "hyperlink_text").text = link["hyperlink_text"]
                            if link.get("hyperlink_text_alt"):
                                LET.SubElement(link_elem, "hyperlink_text_alt").text = link["hyperlink_text_alt"]
                else:
                    link_val = hyperlinks
                    if isinstance(link_val, list):
                        link_val = "; ".join(str(v) for v in link_val)
                    LET.SubElement(hyperlinks_elem, "hyperlink").text = str(link_val)
        # type (optional)
        LET.SubElement(record, "type").text = data.get("type", dummy("type"))
        # description (optional)
        LET.SubElement(record, "description").text = data.get("description", dummy("description"))
        # rel (optional)
        LET.SubElement(record, "rel").text = data.get("rel", dummy("rel"))
        # transcript (optional)
        LET.SubElement(record, "transcript").text = data.get("transcript", dummy("transcript"))
        # transcript_alt (optional)
        LET.SubElement(record, "transcript_alt").text = data.get("transcript_alt", dummy("transcript_alt"))
        # vtt_transcript (optional)
        LET.SubElement(record, "vtt_transcript").text = data.get("vtt_transcript", dummy("vtt_transcript"))
        # vtt_transcript_alt (optional)
        LET.SubElement(record, "vtt_transcript_alt").text = data.get("vtt_transcript_alt", dummy("vtt_transcript_alt"))
        # rights (optional)
        LET.SubElement(record, "rights").text = data.get("rights", dummy("rights"))
        # fmt (optional)
        LET.SubElement(record, "fmt").text = data.get("fmt", dummy("fmt"))
        # usage (optional)
        LET.SubElement(record, "usage").text = data.get("usage", dummy("usage"))
        # userestrict (optional)
        LET.SubElement(record, "userestrict").text = str(data.get("userestrict", dummy("userestrict")))
        # xmllocation (optional)
        LET.SubElement(record, "xmllocation").text = data.get("xmllocation", dummy("xmllocation"))
        # xmlfilename (optional)
        LET.SubElement(record, "xmlfilename").text = data.get("xmlfilename", dummy("xmlfilename"))
        # collection_link (optional)
        LET.SubElement(record, "collection_link").text = data.get("collection_link", dummy("collection_link"))
        # series_link (optional)
        LET.SubElement(record, "series_link").text = data.get("series_link", dummy("series_link"))
        # Return pretty-printed XML string and collected lists
        return LET.tostring(root, pretty_print=True, encoding="unicode"), all_point_keywords, all_point_locations, all_point_synopses

    def _fix_json_array_string(s: str) -> str:
        """
        Fixes a string that's supposed to be a JSON array of objects but has common
        LLM-generated errors, like missing commas or an extra closing brace.
        """
        # 1. Use regex to fix missing commas between objects
        # e.g., '} {' becomes '}, {'
        s_fixed = re.sub(r'\}\s*\{', r'}, {', s)

        # 2. Strip whitespace and check for an erroneous trailing brace
        # e.g., '[...]}' becomes '[...]'
        s_stripped = s_fixed.strip()
        if s_stripped.endswith(']}'):
            s_stripped = s_stripped[:-1]

        return s_stripped
    
    def _call_tool_capable_llm(self, prompt: str) -> dict:
        """
        Call the tool-capable LLM and collect extracted data from tool calls,
        with retry logic and robust parsing for streaming responses.
        """
        global tokens_in, tokens_out
        collected_data = {}

        for attempt in range(1, MAX_LLM_RETRIES + 1):
            try:
                print(f'prompt: {prompt}', flush=True)
                response = client.chat.completions.create(
                    model=DEFAULT_TOOL_CAPABLE_MODEL,
                    messages=[{"role": "user", "content": prompt}],
                    tools=tools,
                    tool_choice="auto",
                    stream=True,
                    timeout=120,
                )

                # Accumulate tool call arguments by tool_call index
                tool_call_args = {}
                tool_call_names = {}

                print("[STREAMING TOOLCALLS...]", flush=True)
                for chunk in response:
                    tool_calls = getattr(chunk.choices[0].delta, "tool_calls", None)
                    if tool_calls:
                        for tc in tool_calls:
                            idx = tc.index
                            if idx not in tool_call_args:
                                tool_call_args[idx] = ""
                                tool_call_names[idx] = tc.function.name

                            arg_piece = getattr(tc.function, "arguments", "")
                            if arg_piece:
                                tool_call_args[idx] += arg_piece
                                tokens_out += count_tokens(arg_piece, model=llm_to_openai[DEFAULT_TOOL_CAPABLE_MODEL])
                                print(f"{arg_piece}", end="", flush=True)

                tokens_in += count_tokens(prompt, model=llm_to_openai[DEFAULT_TOOL_CAPABLE_MODEL])
                logging.info(f"Total tokens in: {tokens_in}")
                logging.info(f"Total tokens out: {tokens_out}")
                print("\n[TOOLCALLS COMPLETE]\n", flush=True)

                # Now parse all accumulated tool call arguments

                for idx, args_str in tool_call_args.items():
                    func_name = tool_call_names[idx]
                    try:
                        decoder = json.JSONDecoder()
                        all_args = []
                        pos = 0
                        s = args_str.strip()
                        while pos < len(s):
                            try:
                                obj, pos = decoder.raw_decode(s, pos)
                                all_args.append(obj)
                            except Exception:
                                pos += 1
                        if not all_args:
                            try:
                                all_args = [json.loads(args_str)]
                            except Exception:
                                pass
                        if len(all_args) > 1:
                            merged_args = {}
                            for arg_obj in all_args:
                                if isinstance(arg_obj, dict):
                                    merged_args.update(arg_obj)
                            args = merged_args if merged_args else all_args[-1]
                        elif all_args:
                            args = all_args[0]
                        else:
                            args = {}
                        # --- END FIX ---

                        # Process parsed arguments based on function name
                        print(f'func_name: {func_name}, args: {args}', flush=True)
                        if func_name == "extract_date":
                            collected_data["date"] = args.get("date")
                        elif func_name == "extract_title":
                            collected_data["title"] = args.get("title")
                        elif func_name == "save_keywords":
                            keywords = args.get("keywords")
                            if keywords is None:
                                logging.error(f"No 'keywords' key found in args for save_keywords: {args}")
                                continue
                            if isinstance(keywords, str):
                                import ast
                                try:
                                    # Try Python literal_eval first (handles single quotes, Python lists)
                                    keywords_eval = ast.literal_eval(keywords)
                                    if isinstance(keywords_eval, list):
                                        keywords = keywords_eval
                                    else:
                                        keywords = [keywords_eval]
                                except Exception:
                                    try:
                                        keywords = json.loads(keywords)
                                    except Exception:
                                        logging.error(f"Failed to parse keywords as JSON or Python literal: {keywords}")
                                        continue
                            collected_data["keywords"] = keywords
                        elif func_name == "extract_duration":
                            collected_data["duration"] = args.get("duration")
                        elif func_name == "extract_interviewer":
                            collected_data["interviewers"] = args.get("interviewers")
                        elif func_name == "extract_interviewee":
                            collected_data["interviewees"] = args.get("interviewees")
                        elif func_name == "extract_points":
                            points = args.get("point")
                            if points is None:
                                logging.error(f"No 'point' key found in args for extract_points: {args}")
                                continue
                            for _ in range(2):
                                if isinstance(points, str):
                                    try:
                                        points = self._fix_json_array_string(points)
                                        points = json.loads(points)
                                    except Exception:
                                        break
                                else:
                                    break
                            collected_data["points"] = points
                        elif func_name == "save_extract_points_text":
                            points_text = args.get("points_text")
                            if points_text is not None:
                                collected_data["points_text"] = points_text
                        elif func_name == "extract_point":
                            point = args.get("point")
                            if point is not None:
                                collected_data["point"] = point
                    except (ValueError, Exception) as e:
                        logging.error(f"Error parsing tool call arguments for {func_name}: {e}\nRaw String: '{args_str}'")

                return collected_data

            except Exception as e:
                logging.error(f"Attempt {attempt}: Error during tool-capable LLM call: {e}")
                # Chunking logic for context length errors
                if (
                    hasattr(e, 'message') and 'maximum context length' in str(e)
                ) or (
                    hasattr(e, 'args') and any('maximum context length' in str(arg) for arg in e.args)
                ):
                    logging.warning("Context length exceeded, chunking input and merging outputs.")
                    # Try to extract the main input from the prompt
                    # We'll look for a 'Here is the input data:' marker
                    import re
                    match = re.search(r"Here is the input data:\n(.+)", prompt, re.DOTALL)
                    if match:
                        input_data = match.group(1)
                    else:
                        input_data = prompt
                    # Chunk input_data into ~8000 words
                    words = input_data.split()
                    chunk_size = 8000
                    chunks = [" ".join(words[i:i+chunk_size]) for i in range(0, len(words), chunk_size)]
                    merged_data = {}
                    for idx, chunk in enumerate(chunks):
                        chunk_prompt = re.sub(r"Here is the input data:\n.+", f"Here is the input data:\n{chunk}", prompt, flags=re.DOTALL)
                        logging.info(f"Processing chunk {idx+1}/{len(chunks)}")
                        try:
                            chunk_result = self._call_tool_capable_llm(chunk_prompt)
                            # Merge dictionaries (for lists, concatenate)
                            for k, v in chunk_result.items():
                                if k in merged_data and isinstance(merged_data[k], list) and isinstance(v, list):
                                    merged_data[k].extend(v)
                                else:
                                    merged_data[k] = v
                        except Exception as ce:
                            logging.error(f"Error processing chunk {idx+1}: {ce}")
                    return merged_data
                if attempt < MAX_LLM_RETRIES:
                    sleep(RETRY_DELAY_SECONDS)
                else:
                    raise

    def generate_xml(self, report: str, schema_path: Optional[str] = None) -> str:
        """
        Use the tool-capable LLM to extract all fields and construct schema-compliant XML.
        Args:
            report: The pseudo-XML or structured data from the large context LLM
            schema_path: Path to the XML schema file
        Returns:
            The validated and corrected XML as a string
        """
        # Extract all fields using the new extraction tools
        extracted = self.extract_fields_with_tools(report)
        # Build XML from extracted fields
        xml = self.build_xml(extracted)
        return xml

    def get_field_extraction_prompt(self, report: str, exclude_points: bool = False, only_tool: str = None) -> str:
        """
        Generate a prompt for the tool-capable LLM to extract all fields needed for OHMS XML construction using the new extraction tools.
        Args:
            report: The pseudo-XML or structured data from the large context LLM
            exclude_points: If True, do not ask for <point> extraction in this prompt
            only_tool: If set, only ask for extraction using this tool
        Returns:
            The prompt string for the tool-capable LLM
        """
        if only_tool:
            if only_tool == "extract_date":
                return f"""
                Use the extract_date tool to extract the interview date (ISO format) from the following data. Reply ONLY by calling extract_date. Do not output any text, XML, or markup directly. If the field is not present, call the tool with a clear placeholder value (e.g., 'REQUIRED').\nHere is the input data:\n{report}
                """
            if only_tool == "extract_title":
                return f"""
                Use the extract_title tool to extract the interview title from the following data. Reply ONLY by calling extract_title. Do not output any text, XML, or markup directly. If the field is not present, call the tool with a clear placeholder value (e.g., 'REQUIRED').\nHere is the input data:\n{report}
                """
            if only_tool == "extract_duration":
                return f"""
                Use the extract_duration tool to extract the duration (HH:MM:SS) from the following data. Reply ONLY by calling extract_duration. Do not output any text, XML, or markup directly. If the field is not present, call the tool with a clear placeholder value (e.g., 'REQUIRED').\nHere is the input data:\n{report}
                """
            if only_tool == "extract_interviewer":
                return f"""
                Use the extract_interviewer tool to extract all interviewer names (as a list) from the following data. Reply ONLY by calling extract_interviewer. Do not output any text, XML, or markup directly. If the field is not present, call the tool with a clear placeholder value (e.g., 'REQUIRED').\nHere is the input data:\n{report}
                """
            if only_tool == "extract_interviewee":
                return f"""
                Use the extract_interviewee tool to extract all interviewee names (as a list) from the following data. Reply ONLY by calling extract_interviewee. Do not output any text, XML, or markup directly. If the field is not present, call the tool with a clear placeholder value (e.g., 'REQUIRED').\nHere is the input data:\n{report}
                """
            if only_tool == "extract_points":
                return f"""
                Use the extract_points tool to extract all <point> elements for the <index> section from the following data. Each point MUST be a JSON object with the following REQUIRED fields: time, title, partial_transcript, synopsis, keywords, and subjects. All other fields are OPTIONAL. For the 'locations' field, ALWAYS return a list of objects (not a string), where each object has: 'location' (coordinates or place name), 'location_text' (optional), 'location_text_alt' (optional), and 'location_zoom' (optional). If no locations, return an empty list. All field values must be plain text, never XML or markup. The tool call must return a single valid JSON object with a 'points' key containing a list of JSON objects, one per point.
                """
        base = """
        You are an expert in oral history metadata extraction. Given the following structured data or pseudo-XML, extract all fields needed for OHMS XML construction using the available extraction tools. For each field, call the appropriate extraction tool:
        - Use extract_date to extract the interview date (ISO format).
        - Use extract_title to extract the interview title.
        - Use extract_duration to extract the duration (HH:MM:SS).
        - Use extract_interviewer to extract all interviewer names (as a list).
        - Use extract_interviewee to extract all interviewee names (as a list).
        All tool calls must return only valid JSON objects, never XML, never pseudo-JSON, and never any markup or tags. Do not include XML or HTML in any field value. Do not include any tags or angle brackets in any field value. Do not double-serialize JSON. Each tool call must return a single valid JSON object with only plain text values for each field.
        """
        if not exclude_points:
            base += """
        - Use extract_points to extract all <point> elements for the <index> section. Each point MUST be a JSON object with the following REQUIRED fields: time, title, partial_transcript, synopsis, keywords, and subjects. All other fields are OPTIONAL. For the 'locations' field, ALWAYS return a list of objects (not a string), where each object has: 'location' (coordinates or place name), 'location_text' (optional), 'location_text_alt' (optional), and 'location_zoom' (optional). If no locations, return an empty list. All field values must be plain text, never XML or markup. Do not include tags or angle brackets in any value. The tool call must return a single valid JSON object with a 'points' key containing a list of JSON objects, one per point.
        """
        base += """
        \nReply ONLY by calling the extraction tools. Do not output any text, XML, or markup directly. If a field is not present, call the tool with a clear placeholder value (e.g., 'REQUIRED').
        \nHere is the input data:
        """ + report
        return base

    def get_single_field_prompt(self, report: str, tool_name: str) -> str:
        """
        Generate a prompt for extracting a single field using the specified tool.
        Args:
            report: The pseudo-XML or structured data from the large context LLM
            tool_name: The name of the extraction tool to use
        Returns:
            The prompt string for the tool-capable LLM
        """
        tool_instructions = {
            "extract_date": "Use extract_date to extract the interview date (ISO format, YYYY-MM-DD).",
            "extract_title": "Use extract_title to extract the interview title.",
            "extract_duration": "Use extract_duration to extract the duration (HH:MM:SS).",
            "extract_interviewer": "Use extract_interviewer to extract all interviewer names (as a list).",
            "extract_interviewee": "Use extract_interviewee to extract all interviewee names (as a list).",
            "extract_points": (
                "Use extract_points to extract all <point> elements for the <index> section. "
                "Each point MUST be a JSON object with the following REQUIRED fields: time, title, partial_transcript, synopsis, keywords, and subjects. "
                "All other fields are OPTIONAL. For the 'locations' field, ALWAYS return a list of objects (not a string), where each object has: 'location' (coordinates or place name), 'location_text' (optional), 'location_text_alt' (optional), and 'location_zoom' (optional). If no locations, return an empty list. All field values must be plain text, never XML or markup. "
                "Do not include tags or angle brackets in any field value. The tool call must return a single valid JSON object with a 'points' key containing a list of JSON objects, one per point."
            ),
            "extract_points_text": (
                "Extract the raw text for each index point from the following pseudo-XML or structured data use the save_extract_points_text function to save it. "
                "Return a list of plain text blocks, one for each index point, exactly as they appear in the input. "
                "Do not summarize, reformat, or structure the text. Do not output any XML, JSON, or markup. "
                "The tool call must return a single valid JSON object with a 'points_text' key containing a list of strings, one per point."
                "Here is an example of a point you would return as an element in the list:\n"
                "  - time: [00:57:15]\n"
                "    title: Racial Dynamics in Education\n"
                '    partial_transcript: "I was surprised they had not integrated before we did back in the mountains..."\n'
                "    synopsis: Contrasts educational segregation experiences between Lynch and Lexington.\n"
                "    keywords: School integration, Rosenwald schools\n"
                "    locations: Lynch KY, Lexington KY\n"
                "That is one entire element. Your job is to extract a list of those, and save them with save_extract_points_text."
            ),
            "extract_point": (
                "Use extract_point to extract a single index point from the following text block. "
                "Return a JSON object with the following REQUIRED fields: time, title, partial_transcript, synopsis, keywords, and subjects. "
                "All other fields are OPTIONAL. For the 'locations' field, ALWAYS return a list of objects (not a string), where each object has: 'location' (coordinates or place name), 'location_text' (optional), 'location_text_alt' (optional), and 'location_zoom' (optional). If no locations, return an empty list. All field values must be plain text, never XML or markup. "
                "Do not include tags or angle brackets in any field value. The tool call must return a single valid JSON object with a 'point' key containing the point object."
            ),
        }
        instruction = tool_instructions.get(tool_name, "")
        prompt = f"""
        You are an expert in oral history metadata extraction. Given the following structured data or pseudo-XML, extract the required field using the specified extraction tool.\n
        {instruction}\n
        Reply ONLY by calling {tool_name}. Do not output any text, XML, or markup directly. If a field is not present, call the tool with a clear placeholder value (e.g., 'REQUIRED').\n
        Here is the input data:\n{report}
        """
        return prompt

    def extract_fields_with_tools(self, report: str) -> dict:
        """
        Use the tool-capable LLM to extract all fields for XML construction using the new extraction tools.
        Each tool call is a separate LLM chat completion.
        Args:
            report: The pseudo-XML or structured data from the large context LLM
        Returns:
            A dictionary of extracted fields (date, title, duration, interviewers, interviewees, points, etc.)
        """
        extracted = {}
        # List of (field, tool_name, result_key)
        tool_fields = [
            ("date", "extract_date", "date"),
            ("title", "extract_title", "title"),
            ("duration", "extract_duration", "duration"),
            ("interviewers", "extract_interviewer", "interviewers"),
            ("interviewees", "extract_interviewee", "interviewees"),
        ]
        for field, tool_name, result_key in tool_fields:
            prompt = self.get_single_field_prompt(report, tool_name)
            result = self._call_tool_capable_llm(prompt)
            value = result.get(result_key)
            # Fix for interviewers/interviewees: ensure list of full names
            if result_key in ("interviewers", "interviewees"):
                if isinstance(value, str):
                    value = [value]
                elif isinstance(value, list):
                    # If it's a list of single characters, join them
                    if value and all(isinstance(x, str) and len(x) == 1 for x in value):
                        value = ["".join(value)]
            if value is not None:
                extracted[result_key] = value

        # Two-stage extraction for points
        prompt_points_text = self.get_single_field_prompt(report, "extract_points_text")
        print("CREATING TEXT POINTS")
        points_text_result = self._call_tool_capable_llm(prompt_points_text)
        print("DONE CREATING TEXT POINTS")
        print(f'points_text_result: {points_text_result}', flush=True)
        points_texts = points_text_result.get("points_text", [])
        print(f'points_texts: {points_texts}', flush=True)
        # Robustly parse points_texts if it's a stringified list
        if isinstance(points_texts, str):
            import ast
            try:
                print(f'Parsing points_texts as literal_eval', flush=True)
                points_texts = ast.literal_eval(points_texts)
            except Exception:
                # Try to fix single quotes to double quotes and parse as JSON
                print('Failed Parsing points_texts as literal_eval', flush=True)
                import json
                try:
                    print('Parsing points_texts as JSON', flush=True)
                    points_texts = json.loads(points_texts.replace("'", '"'))
                except Exception:
                    print('Failed Parsing points_texts as JSON', flush=True)
                    points_texts = []
        points = []
        print(f'points_texts after parsing: {points_texts}', flush=True)
        for pt in points_texts:
            prompt_point = self.get_single_field_prompt(pt, "extract_point")
            print(f'prompt_point: {prompt_point}', flush=True)
            point_result = self._call_tool_capable_llm(prompt_point)
            point = point_result.get("points", point_result.get("point"))
            print(f'point_result: {point_result}', flush=True)
            if point is not None:
                print(f'point: {point}', flush=True)
                # If point is a string, try to parse as JSON
                if isinstance(point, str):
                    try:
                        import ast, json
                        point_obj = ast.literal_eval(point)
                        if not isinstance(point_obj, dict):
                            point_obj = json.loads(point.replace("'", '"'))
                        point = point_obj
                    except Exception:
                        try:
                            point = json.loads(point.replace("'", '"'))
                        except Exception:
                            print(f"Failed to parse point string as dict: {point}", flush=True)
                            continue
                # Fix stringified lists for certain fields
                if isinstance(point, dict):
                    for field in ["keywords", "subjects", "locations"]:
                        val = point.get(field)
                        if isinstance(val, str):
                            try:
                                parsed = ast.literal_eval(val)
                                if isinstance(parsed, list):
                                    point[field] = parsed
                                else:
                                    parsed = json.loads(val.replace("'", '"'))
                                    if isinstance(parsed, list):
                                        point[field] = parsed
                            except Exception:
                                if val.strip() == '[]':
                                    point[field] = []
                                else:
                                    if ',' in val:
                                        point[field] = [v.strip() for v in val.strip('[]').split(',')]
                    points.append(point)
        extracted["points"] = points
        print(f'Final extracted points: {extracted["points"]}', flush=True)
        print('\n\n', flush=True)
        print(f'Final extracted: {extracted}\n\n', flush=True)
        return extracted

    def save_and_validate_xml(self, xml_content: str, schema_path: str, output_path: str, ) -> None:
        """
        Validate the XML content against the schema, pretty-print it, and save to output_path.
        Args:
            xml_content: The XML string to validate and save
            schema_path: Path to the XML schema file
            output_path: Path to save the formatted XML
        Raises:
            ValueError if the XML is not valid against the schema
        """
        import lxml.etree as LET
        # Parse XML
        try:
            xml_doc = LET.fromstring(xml_content.encode('utf-8'))
        except Exception as e:
            logging.error(f"Failed to parse XML: {e}")
            raise ValueError(f"Invalid XML: {e}")
        # Validate against schema
        # try:
        #     with open(schema_path, 'rb') as f:
        #         schema_doc = LET.parse(f)
        #     schema = LET.XMLSchema(schema_doc)
        #     if not schema.validate(xml_doc):
        #         errors = schema.error_log
        #         logging.error(f"XML Schema validation errors:\n{errors}")
        #         raise ValueError(f"XML does not validate against schema: {errors}")
        # except Exception as e:
        #     logging.error(f"Schema validation failed: {e}")
        #     logging.info(f"\n\nXML:\n{xml_content}\n\n")
        #     raise ValueError(f"Schema validation failed: {e}")
            
        # Pretty print and save
        pretty_xml = LET.tostring(xml_doc, pretty_print=True, encoding='unicode')
        with open(output_path, 'w', encoding='utf-8') as f:
            f.write(pretty_xml)
        logging.info(f"Validated and saved formatted XML to {output_path}")

    def process_transcript(self, transcript_path: str, schema_path: str, output_path: str, output_csv_path: str, og_filename: str) -> None:
        """
        Process the transcript, generate a report and XML document, and save it
        Args:
            transcript_path: Path to the transcript file
            schema_path: Path to the XML schema file
            output_path: Path where the XML output should be saved
        """
        transcript_path = Path(transcript_path)
        schema_path = Path(schema_path)
        output_path = Path(output_path)

        transcript = self.load_transcript(transcript_path)
        
        logging.info("Generating report with large context LLM...")
        report = self.generate_initial_report(transcript, schema_path)

        # Fix: construct filename ending with _ohms.txt
        base = output_path.with_suffix('')  # removes the extension
        ohms_txt_path = base.as_posix() + "_ohmsd_index_mach.txt"
        with open(ohms_txt_path, 'w', encoding='utf-8') as f:
            f.write(report)
        
        logging.info("Generating XML with tool-capable LLM...")
        extracted = self.extract_fields_with_tools(report)
        xml_content, all_point_keywords, all_point_locations, all_point_synopses = self.build_xml(extracted, og_filename)
        self.save_and_validate_xml(xml_content, schema_path, output_path)
        logging.info(f"Process complete. XML saved to {output_path}")
        logging.info(f"Starting CSV generation...")
        self.generate_csv_summary_from_xml(
            xml_content=xml_content,
            output_path=output_csv_path,
            all_point_keywords=all_point_keywords,
            all_point_locations=all_point_locations,
            all_point_synopses=all_point_synopses,
            transcript=transcript
        )

    def get_top_100_words(self, text):
        nltk.download('punkt')
        nltk.download('stopwords')

        filler_words = {
            'um', 'uh', 'like', 'you know', 'i mean', 'okay', 'so', 'well', 'actually',
            'basically', 'right', 'kinda', 'sorta', 'literally', 'just', 'hmm', 'huh',
            'yeah', 'no', 'uhhuh', 'uhuh', 'hmmm', 'oh', 'alright', 'anyway'
        }
        combined_stopwords = set(stopwords.words('english')) | filler_words
        # Lowercase the text
        text = text.lower()
        
        # Remove punctuation and non-word characters
        text = re.sub(r"[^\w\s]", "", text)
        
        # Tokenize
        words = word_tokenize(text)

        # Remove filler words
        filtered_words = [word for word in words if word not in combined_stopwords]
                
        # Count frequencies
        word_counts = Counter(filtered_words)
        
        # Get top 100
        top_100 = word_counts.most_common(100)
        
        return top_100


    def generate_csv_summary_from_xml(self, xml_content: str, output_path: str, all_point_keywords=None, all_point_locations=None, all_point_synopses=None, transcript=None) -> None:
        """
        Save the keywords, locations, and synopses of each point directly to CSV and TXT files. Use a tool-calling LLM ONLY to combine all synopses into a 2-paragraph description.
        """
        import csv

        print(f'all_point_keywords: {all_point_keywords}')
        print(f'all_point_locations: {all_point_locations}')
        print(f'all_point_synopses: {all_point_synopses}')

        # remove duplicates from the lists
        if len(all_point_keywords) > 0:
            all_point_keywords = list(set(all_point_keywords))
        else:
            logging.warning("\n\n\nNo keywords provided, Generating keywords from transcript.\n\n\n")
            all_point_keywords = self.extract_keywords(transcript)
            logging.info(f"Generated keywords: {all_point_keywords}\n\n\n\n")
        if all_point_synopses is not None:
            all_point_synopses = list(set(all_point_synopses))
            
        # Write keywords CSV
        base_path, _ = os.path.splitext(output_path)
        keywords_path = base_path + "_keywords_mach.csv"
        with open(keywords_path, 'w', encoding='utf-8', newline='') as f:
            writer = csv.writer(f, delimiter=';')
            writer.writerow(["keyword"])
            for k in all_point_keywords or []:
                writer.writerow([k])
        # Write locations CSV using NER results
        ner = self.generate_ner(transcript=transcript)
        gpe_places = set(ent.text for ent in ner.ents if ent.label_ in ("GPE", "LOC"))
        locations_path = base_path + "_locations_mach.csv"
        with open(locations_path, 'w', encoding='utf-8', newline='') as f:
            writer = csv.DictWriter(f, fieldnames=["place", "gps"])
            writer.writeheader()
            for place in sorted(gpe_places):
                gps = self.geocode_location(place)
                writer.writerow({"place": place, "gps": gps})
        # Write TXT and combined CSV (description will be generated below)
        # Use large context LLM to summarize the interview directly from the transcript
        summary_prompt = (
            "You are an expert oral history analyst. Summarize the following interview transcript in 2 paragraphs (at least 8 sentences total). The summary should capture the main themes, topics, and narrative arc of the interview. Do not include any markup, tags, or formatting.\n\nTranscript:\n" + transcript
        )
        try:
            response = client.chat.completions.create(
                model=DEFAULT_LARGE_CONTEXT_MODEL,
                messages=[{"role": "user", "content": summary_prompt}],
                stream=True,
                timeout=120,
            )
            description_chunks = []
            for chunk in response:
                content = getattr(chunk.choices[0].delta, "content", None)
                if content:
                    description_chunks.append(content)
                    print(content, end="", flush=True)
            description = "".join(description_chunks)
            # Remove any trailing </think> or similar artifacts
            if description and "</think>" in description:
                description = description.split("</think>")[-1].strip()
        except Exception as e:
            logging.error(f"Error generating interview description: {e}")
            description = ""

        ## Create a BIO of the interview subject ########
        bio_prompt = (
            "You are an expert oral history analyst. Create a brief bio of the interview subject based on the following transcript. Do not include any markup, tags, or formatting.\n\nTranscript:\n" + transcript
        )
        try:
            response = client.chat.completions.create(
                model=DEFAULT_LARGE_CONTEXT_MODEL,
                messages=[{"role": "user", "content": bio_prompt}],
                stream=True,
                timeout=120,
            )
            bio_chunks = []
            for chunk in response:
                content = getattr(chunk.choices[0].delta, "content", None)
                if content:
                    bio_chunks.append(content)
                    print(content, end="", flush=True)
            bio = "".join(bio_chunks)
            # Remove any trailing </think> or similar artifacts
            if bio and "</think>" in bio:
                bio = bio.split("</think>")[-1].strip()
        except Exception as e:
            logging.error(f"Error generating interview bio: {e}")
            bio = ""

        # 2. define tool to extract questions
        primary_questions_tool_defs = [
            {
                "type": "function",
                "function": {
                    "name": "save_interview_primary_questions",
                    "description": "Save primary questions from the interview.",
                    "parameters": {
                        "type": "object",
                        "properties": {
                            "questions": {"type": "array", "items": {"type": "string"}, "description": "A list of the questions asked during the interview."}
                        },
                        "required": ["questions"],
                        "additionalProperties": False
                    },
                    "strict": True
                }
            }
        ]
        # 1. Extract primary questions from transcript using LLM (DeepSeek or similar)
        primary_questions_prompt = (
            "You are an expert oral history analyst. Extract all questions asked by the interviewer in the following transcript. "
            "Return each question as a plain text string, one per line. Include substantive, open-ended questions that drive the narrative. Also include minor and follow-up questions.\n"
            "Include context if necessary for a question.\n\n"
            f"Transcript:\n{transcript}\n"
        )
        # Call your LLM (replace with your actual streaming LLM call)
        try:
            primary_questions = []
            response = client.chat.completions.create(
                model=DEFAULT_LARGE_CONTEXT_MODEL,  # or your DeepSeek model
                messages=[{"role": "user", "content": primary_questions_prompt}],
                stream=True,
                timeout=120,
            )
            question_response = ""
            for chunk in response:
                content = getattr(chunk.choices[0].delta, "content", None)
                if content:
                    # Split lines, filter empty
                    question_response += content
                    print(content, end="", flush=True)
        except Exception as e:
            logging.error(f"Error extracting primary questions: {e}")

        # 3. Use tool-calling LLM to structure each question
        # Parse the question_response into a list of questions (one per line, non-empty)
        primary_questions = question_response.split("</think>")[-1].strip()
        # Now send each question to the tool-calling LLM to structure them
        tool_prompt = (
            "You are given a list of primary questions asked by the interviewer. "
            "Save all questions by calling save_interview_primary_questions with the questions.\n"
            f"Questions:\n{primary_questions}\n"
        )

        print(f'tool_prompt: \n{tool_prompt}\n')

        tool_call_args = {}
        tool_call_names = {}
        try:
            response = client.chat.completions.create(
                model=DEFAULT_TOOL_CAPABLE_MODEL,
                messages=[{"role": "user", "content": tool_prompt}],
                tools=primary_questions_tool_defs,
                tool_choice="auto",
                stream=True,
                timeout=120,
            )
            for chunk in response:
                tool_calls = getattr(chunk.choices[0].delta, "tool_calls", None)
                if tool_calls:
                    for tc in tool_calls:
                        idx = tc.index
                        if idx not in tool_call_args:
                            tool_call_args[idx] = ""
                            tool_call_names[idx] = tc.function.name
                        arg_piece = getattr(tc.function, "arguments", "")
                        if arg_piece is None:
                            arg_piece = ""
                        tool_call_args[idx] += arg_piece
                        print(f"{arg_piece}", end="", flush=True)

            print(f"\ntool_call_args: {tool_call_args}\n\n")
            # Parse out the structured questions
            structured_questions = []
            for idx, args_str in tool_call_args.items():
                func_name = tool_call_names[idx]
                try:
                    json_match = re.search(r"\{.*?\}", args_str, re.DOTALL)
                    if json_match:
                        json_str = json_match.group(0)
                    else:
                        json_str = args_str.strip()
                    args = json.loads(json_str)
                    if func_name == "save_interview_primary_question":
                        q = args.get("description", "")
                        if q:
                            structured_questions.append(q)
                except Exception as e:
                    logging.error(f"Error parsing tool call arguments for {func_name}: {e}\nRaw: {args_str}")
            primary_questions = structured_questions
        except Exception as e:
            logging.error(f"Error structuring primary questions: {e}")
        
        # Write description CSV
        desc_path = base_path + "_description_mach.csv"
        with open(desc_path, 'w', encoding='utf-8', newline='') as f:
            writer = csv.writer(f)
            writer.writerow(["description"])
            writer.writerow([description])

        bio_path = base_path + "_bio_mach.csv"
        with open(bio_path, 'w', encoding='utf-8', newline='') as f:
            writer = csv.writer(f)
            writer.writerow(["bio"])
            writer.writerow([bio])
            
        # Write combined CSV (update to use NER GPEs for places)
        combined_path = base_path + "_interview_metadata_combined.csv"
        with open(combined_path, 'w', encoding='utf-8', newline='') as f:
            writer = csv.DictWriter(f, fieldnames=["description", "keywords", "places", "bio"])
            writer.writeheader()
            writer.writerow({
                "description": description,
                "keywords": "; ".join(all_point_keywords or []),
                "places": ", ".join(sorted(gpe_places)),
                "bio": bio
            })
        # Write TXT report (update to use NER GPEs for PLACENAMES section)
        cimr_path = base_path + "_cimr_mach.txt"
        with open(cimr_path, 'w', encoding='utf-8') as f:
            f.write("INTERVIEW DESCRIPTION\n---------------------\n" + description + "\n\n")
            f.write("INTERVIEW KEYWORDS (TOPICS/THEMES)\n---------------------\n" + "; ".join(all_point_keywords or []) + "\n\n")
            f.write("PLACENAMES + GPS COORDINATES\n---------------------\n")
            for place in sorted(gpe_places):
                gps = self.geocode_location(place)
                f.write(f"{place}\t{gps}\n")
            f.write("\n")
            f.write("SUBJECT BIO\n---------------------\n" + bio + "\n\n")
            f.write("INTERVIEW PRIMARY QUESTIONS\n---------------------\n\n")
            # Write each structured question from the tool call results, one per line
            for elem in tool_call_args.values():
                # Find all JSON objects in the string
                for match in re.finditer(r'\{.*?\}', elem, re.DOTALL):
                    try:
                        funct_dict = json.loads(match.group(0))
                        if "questions" in funct_dict:
                            questions = funct_dict["questions"]
                            if questions[0] == '[':
                                questions = json.loads(questions) # sometimes the LLM messes up the output and gives the questions as a string that must be loaded into a python list
                            for question in questions:
                                f.write(f"- {question}\n")
                            break # Stop after processing the first valid JSON object
                    except Exception as e:
                        logging.error(f"Error parsing tool call JSON: {e}\nRaw: {match.group(0)}")            
            f.write("\n")
            f.write("FULL NER\n---------------------\n")
            # Only include PERSON, ORG, GPE, LOC, EVENT
            allowed_labels = {"PERSON", "ORG", "GPE", "LOC", "EVENT"}
            entities = defaultdict(list)
            for ent in ner.ents:
                if ent.label_ in allowed_labels:
                    entities[ent.label_].append(ent.text)
            for label in sorted(entities.keys()):
                f.write(f"\n{label}:\n")
                # Remove duplicates and sort
                for t in sorted(set(entities[label])):
                    f.write(f" - {t}\n")
            f.write("\n")
            f.write("WORD FREQUENCY\n---------------------\n")
            top_words = self.get_top_100_words(transcript)
            for rank, (word, freq) in enumerate(top_words, start=1):
                f.write(f"{rank:3}. {word:<15} {freq}\n")
        logging.info(f"Generated all interview summary outputs at {base_path}_* (CSV/TXT)")

    def generate_ner(self, transcript):
        """
        Generate Named Entity Recognition (NER) data from the transcript.
        Args:
            transcript: The transcript text to analyze
        Returns:
            A list of named entities found in the transcript
        """
        nlp = spacy.load("en_core_web_trf")
        logging.info("Running NER...")
        doc = nlp(transcript)
        logging.info("NER complete.")
        return doc

    def geocode_location(self, location: str) -> str:
        """
        Geocode a location string to GPS coordinates (lat,lon as string), or return None if not found.
        Uses Nominatim (OpenStreetMap) API for geocoding.
        Args:
            location: The location string to geocode
        Returns:
            A string of the form 'lat,lon' if found, else None
        """
        import requests
        if not location or not isinstance(location, str):
            return None
        try:
            url = "https://nominatim.openstreetmap.org/search"
            params = {
                "q": location,
                "format": "json",
                "limit": 1
            }
            headers = {"User-Agent": "cat-talk-ohmsifier/1.0 (contact: vaiden.logan@uky.edu)"}
            resp = requests.get(url, params=params, headers=headers, timeout=10)
            if resp.status_code == 200:
                results = resp.json()
                if results:
                    lat = results[0]["lat"]
                    lon = results[0]["lon"]
                    return f"{lat},{lon}"
        except Exception as e:
            logging.warning(f"Geocoding failed for location '{location}': {e}")
        return None

# --- ClearML and S3 integration for OHMSifier ---
import shutil
from clearml import Task, Dataset, Logger
from pathlib import Path
import os
import argparse
import boto3
from s3transfer import S3Transfer, TransferConfig
import configparser
import traceback

def main_clearml():
    try:
        parser = argparse.ArgumentParser(description="Process an oral history transcript into OHMS XML using ClearML and S3.")
        parser.add_argument("--project_name", type=str, default="SpeakEZ", help="ClearML project name")
        parser.add_argument("--task_name", type=str, default="ohmsifier_task", help="ClearML task name")
        parser.add_argument('--dataset_path', type=str, default='/workspace/custom_data', help='location of dataset') # this is necessary but idk what it does
        parser.add_argument("--dataset_project", type=str, default="SpeakEZ_Datasets", help="ClearML dataset project")
        parser.add_argument("--project_id", type=str, default='TEMPLATE_DATASET_DO_NOT_REMOVE', help="Project/dataset id")
        parser.add_argument("--collection_id", type=str, default='TEMPLATE_COLLECTION_DO_NOT_REMOVE', help="Collection id")
        parser.add_argument('--file_id', type=str, default='fitnessgram', help='clearml dataset file id')
        parser.add_argument('--job_id', type=str, help='id of job')
        parser.add_argument("--schema", default='/workspace/ohms.xsd', help="Path to the local OHMS XSD schema file")
        parser.add_argument("--output_s3_bucket", default='output', help="S3 bucket to upload the OHMS XML output")
        parser.add_argument("--output_s3_prefix", default="", help="S3 prefix/path for the output XML")
        parser.add_argument("--og_filename", type=str, default="default_title.txt", help="Original filename of the transcript to process")
        args = parser.parse_args()

        print('ENVS:')
        for name, value in os.environ.items():
            print("{0}: {1}".format(name, value))

        print('\nARGS:')
        for name, value in vars(args).items():
            print(f'{name} = {value}')

        # Start ClearML task
        task = Task.init(project_name=args.project_name, task_name=args.task_name, output_uri='s3://s3.ai.uky.edu:443/cat-talk')
        Logger.current_logger().report_text("Starting OHMSifier ClearML Task", print_console=True)
        task_id = str(task.current_task().id)

        # Pull transcript from S3 via ClearML Dataset
        clearml_dataset_name = f'{args.project_id}_{args.file_id}_outputs'
        clearml_dataset = Dataset.get(dataset_name=clearml_dataset_name, dataset_project=args.dataset_project)
        dataset_cache_path = clearml_dataset.get_local_copy()
        transcript_file = next((f for f in os.listdir(dataset_cache_path) if f.endswith('.transcript')), None)
        if not transcript_file:
            raise FileNotFoundError("No .transcript file found in dataset cache!")
        transcript_path = os.path.join(dataset_cache_path, transcript_file)

        # Prepare output path
        output_xml_name = os.path.splitext(transcript_file)[0] + '_ohmsd_index_mach.xml'
        output_csv_name = os.path.splitext(transcript_file)[0] + '.csv'
        outputs_local_dir = os.environ.get('OUTPUT_DIR')
        output_xml_path = os.path.join(outputs_local_dir, output_xml_name)
        output_csv_path = os.path.join(outputs_local_dir, output_csv_name)
        og_filename = args.og_filename.split('.')[0]  # Remove extension for OHMS title


        # Run OHMSifier pipeline
        processor = TranscriptProcessor(os.environ.get("LARGE_CONTEXT_API_KEY", "your_key_here"), os.environ.get("TOOL_CAPABLE_API_KEY", "your_key_here"))
        processor.process_transcript(transcript_path, args.schema, output_xml_path, output_csv_path, og_filename)

        print("\nGenerated files:")
        for fname in os.listdir(outputs_local_dir):
            print(" -", fname)
        # Print the contents of all generated files
        print("\n--- File Contents ---")
        for fname in os.listdir(outputs_local_dir):
            fpath = os.path.join(outputs_local_dir, fname)
            print(f"\n>>> {fname} <<<")
            try:
                with open(fpath, "r", encoding="utf-8") as f:
                    content = f.read()
                    print(content)
            except Exception as e:
                print(f"[Could not read file: {e}]")
        desc_csv = os.path.splitext(outputs_local_dir)[0] + "_description_mach.csv"
        if os.path.exists(desc_csv):
            with open(desc_csv, "r", encoding="utf-8") as f:
                print("\n--- Description CSV ---")
                print(f.read())

        Logger.current_logger().report_text(f"Generated OHMS XML: {output_xml_path}", print_console=True)

        # Upload XML to S3
        
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
    

        describalizer_update_url = callback_url + '/updates/describalizer'
        update_status(args.file_id, 'describalizer', 'complete', 'Ready for further analysis', describalizer_update_url)

        describalizer_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'describalizer', 'complete', '', describalizer_update_url)

        # Update metrics
        global tokens_in, tokens_out
        print(f"[LLM METRICS] Final tokens_in: {tokens_in}, tokens_out: {tokens_out}")
        update_metrics(task_id, 'llm', {
            'tokens_in': tokens_in,
            'tokens_out': tokens_out
        }, cat_talk_callback_url + '/metrics/update')

    except Exception as e:
        print(e)
        traceback.print_exc()
        describalizer_update_url = callback_url + '/updates/describalizer'
        update_status(args.file_id, 'describalizer', 'failed', '', describalizer_update_url)

        describalizer_update_url = cat_talk_callback_url + '/updates'
        update_status(args.file_id, 'describalizer', 'failed', '', describalizer_update_url)

    task.close()


# Add new entrypoint
if __name__ == "__main__":
    import sys
    if '--local' in sys.argv:
        main()
        
    else:
        main_clearml()

