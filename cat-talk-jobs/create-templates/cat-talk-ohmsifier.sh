cd /workspace

export UUID=$(uuidgen)
export WORKING_DIR=/tmp/working_dir/$UUID
export OUTPUT_DIR=$WORKING_DIR/output
export HOME=$WORKING_DIR
export CLEARML_CONFIG_FILE="/workspace/clearml.conf"
export CLEARML_CACHE_DIR=$WORKING_DIR/.clearml # this might be the only import clearml env var
export SPACY_CACHE=/tmp/working_dir
export NLTK_DATA=$HOME/nltk_data

# Where models are cached
export TORCH_HOME=/tmp/working_dir/cache/torch
export TRANSFORMERS_CACHE=/tmp/working_dir/cache/huggingface/transformers
export HF_HOME=/tmp/working_dir/cache/huggingface
export SPEECHBRAIN_CACHE=/tmp/working_dir/cache/speechbrain

mkdir -p $WORKING_DIR
mkdir -p $OUTPUT_DIR
python3 -c "import nltk;nltk.download('punkt_tab')"

/usr/bin/python3 /workspace/training-wrappers/ohmsifier_clearml_wrapper.py --job_id asdf --task_name ohmsifier_template_v0

rm -rf $WORKING_DIR
