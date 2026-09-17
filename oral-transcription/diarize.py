# import torch
import sys
import os
import time

print(f'argv: {sys.argv}')
if len(sys.argv) != 2:
    print(f'Usage: python {sys.argv[0]} <filepath>')
    sys.exit(1)

# Audio file path
audio_file = sys.argv[1]

# instantiate the pipeline
from pyannote.audio import Pipeline
import wave
import contextlib
import numpy as np

pipeline_config = {
    'sad': {
        'model_duration': 2.0
    },
    'scd': {
        'min_duration_on': 0.1,  # Speaker change detection minimum duration on
        'min_duration_off': 0.1,  # Speaker change detection minimum duration off
        'min_duration_overlap': 0.0,  # Minimum overlap duration
        'min_duration_coverage': 0.7  # Minimum coverage duration
    },
    'overlap_detection': True  # Enable overlap detection
}

pipeline = Pipeline.from_pretrained(
    'pyannote/speaker-diarization-3.1',
    use_auth_token=os.environ.get('HF_TOKEN', ''),  # HuggingFace token with access to pyannote models (set in environment)
    # **pipeline_config
)
# print(pipeline.__dict__.keys())
print(pipeline._parameters.__dict__)

# Define audio file path
# audio_file = "meow.wav"

# Preprocess audio if needed (example: normalize audio)
def normalize_audio(audio_path):
    with wave.open(audio_path, 'rb') as wf:
        params = wf.getparams()
        frames = wf.readframes(params.nframes)
        audio_data = np.frombuffer(frames, dtype=np.int16)

        # Normalize audio data
        max_val = np.max(np.abs(audio_data))
        audio_data = (audio_data / max_val * 32767).astype(np.int16)

        # Write normalized audio back
        with wave.open(audio_path, 'wb') as wf_normalized:
            wf_normalized.setparams(params)
            wf_normalized.writeframes(audio_data.tobytes())

# normalize_audio(audio_file)

pipeline._parameters = pipeline_config
pipeline.instantiate(pipeline_config)

# run the pipeline on an audio file
diarization = pipeline(audio_file, num_speakers=2)

# Post-process diarization result
# Merge short segments of the same speaker, smooth boundaries, etc.
from pyannote.core import Segment

# Example of merging short segments
def merge_short_segments(diarization, min_duration=0.5):
    new_diarization = diarization.empty()
    for turn, _, speaker in diarization.itertracks(yield_label=True):
        if turn.duration < min_duration:
            if len(new_diarization) > 0 and new_diarization[-1].track.label == speaker:
                new_diarization[-1].track.segment = Segment(new_diarization[-1].track.segment.start, turn.end)
            else:
                new_diarization[turn] = speaker
        else:
            new_diarization[turn] = speaker
    return new_diarization

# diarization = merge_short_segments(diarization)

# dump the diarization output to disk using RTTM format
rttm_file_path = f'output/{os.path.splitext(os.path.basename(audio_file))[0]}.rttm'
with open(rttm_file_path, 'w+') as rttm:
    diarization.write_rttm(rttm)