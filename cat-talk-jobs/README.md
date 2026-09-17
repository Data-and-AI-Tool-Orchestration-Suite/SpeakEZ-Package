# cat-talk-jobs

Repo for running cat-talk jobs.

## How to use this repo

This repo allows for different types of cat-talk jobs to be run from different locations. For example, one machine can run verbatimizer jobs while a different machine runs custom jobs.
This is accomplished by changing the job_types in config.ini
If you are using a machine to run the verbatimizer or synchronifier, the .env file must contain USE_GPU=1.
Jobs that don't use GPUs (custom jobs, riskalyzer, ohmsifier) get USE_GPU=0 in the .env. This is because the are doing API calls to LLM Factory instead of directly using a GPU.
If using a GPU, you can specify the GPU ID in the docker-compose.yml file.

clearml.conf must also be created.

After that, run:
```bash
docker compose up -d
```

The system is now checking the job queue and running the jobs specified in config.ini

## Help

Contact vaiden.logan@uky.edu for help or questions.