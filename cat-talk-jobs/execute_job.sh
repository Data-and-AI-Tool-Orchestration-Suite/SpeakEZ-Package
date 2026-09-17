cd /workspace

export UUID=$(uuidgen)
export WORKING_DIR=/tmp/working_dir/$UUID
export OUTPUT_DIR=$WORKING_DIR/output
export HOME=$WORKING_DIR

export CLEARML_AGENT_SKIP_PYTHON_ENV_INSTALL=1
export CLEARML_VENVS_BUILDS=$WORKING_DIR/.clearml/venvs-builds
export CLEARML_CACHE_DIR=$WORKING_DIR/.clearml # this might be the only import clearml env var
export CLEARML_VCS_CACHE=$WORKING_DIR/.clearml/vcs-cache
export CLEARML_PIP_CACHE=$WORKING_DIR/.clearml/pip-download-cache
export CLEARML_DOCKER_PIP_CACHE=$WORKING_DIR/.clearml/pip-cache
export CLEARML_APT_CACHE=$WORKING_DIR/.clearml/apt-cache
export CLEARML_TASK_NO_REUSE="1"
export CLEARML_LOG_LEVEL="INFO"
export CLEARML_CONFIG_FILE="/workspace/clearml.conf"
export NVIDIA_DRIVER_CAPABILITIES=compute,utility
export LD_LIBRARY_PATH=$(python3 -c 'import os; import nvidia.cublas.lib; import nvidia.cudnn.lib; print(os.path.dirname(nvidia.cublas.lib.__file__) + ":" + os.path.dirname(nvidia.cudnn.lib.__file__))')
export NLTK_DATA=$HOME/nltk_data
export SPACY_CACHE=/tmp/working_dir
export LD_PRELOAD=/usr/lib/x86_64-linux-gnu/libmkl_intel_lp64.so:/usr/lib/x86_64-linux-gnu/libmkl_sequential.so:/usr/lib/x86_64-linux-gnu/libmkl_core.so:/usr/lib/x86_64-linux-gnu/libstdc++.so.6
export OMP_NUM_THREADS=1

# Where models are cached
export TORCH_HOME=/tmp/working_dir/cache/torch
export TRANSFORMERS_CACHE=/tmp/working_dir/cache/huggingface/transformers
export HF_HOME=/tmp/working_dir/cache/huggingface
export SPEECHBRAIN_CACHE=/tmp/working_dir/cache/speechbrain

python3 -c "import nltk;nltk.download('punkt_tab')"

mkdir -p $WORKING_DIR
mkdir -p $OUTPUT_DIR

cd $WORKING_DIR

# Retry logic for clearml-agent execute --id $1 (5 attempts)
RETRIES=5
COUNT=0
SUCCESS=0
while [ $COUNT -lt $RETRIES ]; do
    clearml-agent execute --id $1 && SUCCESS=1 && break
    COUNT=$((COUNT+1))
    # Generate a random sleep time between 5 and 20 seconds
    SLEEP_TIME=$(( (RANDOM % 16) + 5 ))
    echo "clearml-agent failed (attempt $COUNT/$RETRIES), retrying in $SLEEP_TIME seconds..."
    sleep $SLEEP_TIME
done
if [ $SUCCESS -ne 1 ]; then
    echo "clearml-agent failed after $RETRIES attempts."
    exit 1
fi

rm -rf $WORKING_DIR
