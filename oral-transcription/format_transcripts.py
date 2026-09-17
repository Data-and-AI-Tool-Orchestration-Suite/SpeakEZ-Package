import json
import os

def convert_transcript_format(default_transcript, target_format):
    """
    Convert a transcript from the default format to other formats.
    
    Args:
        default_transcript (str): The transcript in default format
        target_format (str): Output format ('WEBVTT', 'WEBVTT-caption', 'txt', 'bbt', 'srt')
    
    Returns:
        str: The formatted transcript
    """
    # Helper function to format timestamps
    def format_timestamp(timestamp_str, use_comma=False):
        """Converts HH:MM:SS.mm format to the same format but with optional comma instead of period."""
        if use_comma:
            return timestamp_str.replace('.', ',')
        return timestamp_str
    
    # Parse the default transcript into structured data
    segments = []
    lines = default_transcript.strip().split('\n')
    
    for line in lines:
        # Extract data using regex pattern matching
        try:
            # Parse: "01 [00:00:00.00 - 00:00:01.32] interviewer: What's your name?"
            parts = line.split(' [', 1)
            if len(parts) < 2:
                continue
                
            index = parts[0].strip()
            rest = parts[1]
            
            timestamp_text = rest.split('] ', 1)
            if len(timestamp_text) < 2:
                continue
                
            timestamps = timestamp_text[0]
            content = timestamp_text[1]
            
            # Split timestamps
            start_time, end_time = timestamps.split(' - ')
            
            # Split speaker and text
            speaker_text = content.split(': ', 1)
            if len(speaker_text) < 2:
                speaker = "UNKNOWN"
                text = content
            else:
                speaker = speaker_text[0]
                text = speaker_text[1]
            
            segments.append({
                'index': index,
                'start': start_time,
                'end': end_time,
                'speaker': speaker,
                'text': text
            })
        except Exception as e:
            print(f"Warning: Error parsing line: {line}\nError: {str(e)}")
            continue
    
    # Format the output based on target format
    output_lines = []
    
    if target_format in ['WEBVTT', 'WEBVTT-caption']:
        output_lines.append('WEBVTT\n')
    
    # Consolidate segments by speaker for certain formats
    if target_format in ['WEBVTT', 'txt', 'default']:
        consolidated_segments = []
        current_speaker = None
        current_segment = None
        
        for segment in segments:
            if segment['speaker'] != current_speaker:
                if current_segment:
                    consolidated_segments.append(current_segment)
                current_speaker = segment['speaker']
                current_segment = segment.copy()
            else:
                # Combine text and update end time
                current_segment['text'] += " " + segment['text']
                current_segment['end'] = segment['end']
        
        # Add the last segment
        if current_segment:
            consolidated_segments.append(current_segment)
        
        segments = consolidated_segments
    
    for i, segment in enumerate(segments):
        start_formatted = format_timestamp(segment['start'], use_comma=(target_format == 'srt'))
        end_formatted = format_timestamp(segment['end'], use_comma=(target_format == 'srt'))
        
        if target_format == 'default':
            output_lines.append(f"{i+1:02d} [{start_formatted} - {end_formatted}] {segment['speaker']}: {segment['text']}")
        
        elif target_format == 'WEBVTT':
            output_lines.append(f"{i+1}\n{start_formatted} --> {end_formatted}\n<v {segment['speaker']}> {segment['text']}\n")
        
        elif target_format == 'WEBVTT-caption':
            output_lines.append(f"{i+1}\n{start_formatted} --> {end_formatted}\n{segment['text']}\n")
        
        elif target_format == 'txt':
            output_lines.append(f"[{start_formatted}]\n{segment['speaker']}: {segment['text']}\n")
        
        elif target_format == 'bbt':
            output_lines.append(f"{segment['text']}")
        
        elif target_format == 'srt':
            output_lines.append(f"{i+1}\n{start_formatted} --> {end_formatted}\n{segment['text']}\n")
    
    return "\n".join(output_lines)