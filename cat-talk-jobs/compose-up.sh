#!/bin/bash

# Load .env file
set -a
source .env
set +a

# Conditionally add GPU override
if [ "$USE_GPU" = "1" ]; then
  docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d $1
else
  docker compose -f docker-compose.yml up -d $1
fi
