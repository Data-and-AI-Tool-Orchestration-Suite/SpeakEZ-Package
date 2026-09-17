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

if len(sys.argv) != 2:
    print(f'Usage: python {sys.argv[0]} <filepath>')
    sys.exit(1)

# Audio file path
audio_file = sys.argv[1]


def timestamp_to_float(minutes, seconds, ms=0):
    return (minutes*60)+seconds+(ms/1000)

# audio_file = './meow.wav'

# clip_start = timestamp_to_float(21, 30)
# clip_end   = timestamp_to_float(22, 0)
# clip_timestamps = f'{clip_start},{clip_end}'

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


