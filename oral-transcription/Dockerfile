#FROM python:3.10.13-alpine
FROM ubuntu:latest
ENV DEBIAN_FRONTEND=noninteractive

USER root
RUN mkdir /workspace/
# COPY api.py /workspace
COPY ./* /workspace/
WORKDIR /workspace/

RUN apt-get update
RUN apt-get update --fix-missing && \
    apt-get install -y \
    build-essential \
    cmake \
    git \
    wget \
    swig \
    python3-dev \
    python3-pip \
    docker.io
    
# (dl.min.io no longer serves mc releases; pinned GitHub release instead)
RUN wget https://github.com/minio/mc/releases/download/RELEASE.2025-08-13T08-35-41Z/mc.linux-amd64.RELEASE.2025-08-13T08-35-41Z -O mc
RUN chmod +x mc
RUN cp mc /usr/bin
#RUN pip3 install --upgrade pip3

# Use the break-system-packages flag because the container gets mad if you don't (it's a little baby)
# If anyone knows how to fix this, please do. If it's not a problem, then good.
RUN pip3 install -r requirements.txt --break-system-packages

RUN chmod +x /workspace/startup.sh