FROM nvidia/cuda:12.3.2-cudnn9-runtime-ubuntu22.04

# Install Python and pip
RUN apt-get update && \
    apt-get install -y python3 python3-pip ffmpeg uuid-runtime vim && \
    rm -rf /var/lib/apt/lists/*

# Set the working directory
WORKDIR /workspace

# Copy only requirements.txt for dependency installation
COPY requirements.txt .

# Install Python dependencies
RUN python3 -m pip install --upgrade pip
RUN pip install "Cython<3.0.0" && \
    PIP_NO_BUILD_ISOLATION=false pip install --no-cache-dir -v -r requirements.txt
RUN pip install -U ctranslate2
RUN mkdir -p /tmp/working_dir
RUN SPACY_CACHE=/tmp/working_dir python3 -m spacy download en_core_web_trf
RUN NLTK_DATA=/tmp/working_dir/nltk_data python3 -c "import nltk; nltk.download('punkt_tab')"

# For development: mount your code with -v so you don't need to COPY . .
# Example: docker run -it --rm -v %cd%:/workspace ubuntu:22.04 bash

CMD ["bash"]