# ClearML Server — Setup Guide

This guide explains how to set up **ClearML** for SpeakEZ — written for
someone who is not a software developer, in the same spirit as `SETUP.md`.
Read `SETUP.md` first; this guide covers the piece of infrastructure that
`SETUP.md` Section 2 assumes you already have.

---

## 1. What ClearML does in SpeakEZ

ClearML is the **job coordinator**. When a user starts a transcription or
analysis job on the website, the job coordinator turns that request into a
ClearML *task* and puts it in a ClearML *queue*. The worker machine watches
that queue, picks up tasks, runs them, and reports status back. ClearML also
stores *datasets* (registered copies of the audio that jobs work from) and
keeps the logs and results of every job.

Three pieces of SpeakEZ talk to ClearML:

| Piece | What it does with ClearML |
|---|---|
| `oral-transcription` (job coordinator) | Creates tasks by copying templates, enqueues them |
| `cat-talk-jobs` (workers) | Runs the tasks from the queue |
| (both) | Store/fetch datasets and upload logs |

You have two options for where ClearML lives. **Pick one:**

- **Option A — the free cloud service** (`app.clearml.ai`). Zero
  installation; fine for evaluation and small teams.
- **Option B — your own server.** Full control, everything stays on your
  infrastructure (relevant for oral-history content policies). Needs one
  Linux machine with Docker and ~8 GB of memory.

If your organization **already runs a ClearML server** (UKY does — the
original deployment used `clearml.ai.uky.edu`), you can skip both options:
just ask its administrator for a user account and API credentials, then jump
to Section 4.

---

## 2. Option A — the free cloud service

1. Sign up at <https://app.clearml.ai> (free).
2. Click your profile picture (top right) → **Settings → API Keys →
   Create new secret key**. Save the **access key** and **secret key**.
3. Continue to Section 3.

✔ **Checkpoint:** you have two long strings (access key + secret key).

---

## 3. Option B — your own ClearML server

### 3.1 Install

On a Linux machine with Docker installed (see `SETUP.md` Section 3 — you do
**not** need the GPU steps for this machine):

```bash
cd ~
git clone https://github.com/allegroai/clearml-server.git
cd clearml-server
docker compose up -d
```

The first start pulls several images and takes a few minutes. It runs six
containers (the web app, the API, a file server, and three databases).

> **Windows note:** run this inside Docker Desktop or a Linux virtual
> machine — the server is built for Linux.

### 3.2 Make it reachable

The server listens on three ports on that machine:

| Port | What it is | Used by |
|---|---|---|
| **8080** | The web UI (your browser) | you, to manage everything |
| **8008** | The API (programs talk here) | job coordinator + workers |
| **8081** | File storage (job files/logs) | job coordinator + workers |

Make sure your firewall lets the web server and the worker machine reach
ports **8008 and 8081**, and that *you* can reach **8080** from your
browser.

### 3.3 Create the admin user and API keys

1. Open `http://<server-ip>:8080` in your browser.
2. Click **Login** → **Register** and create the first account — the first
   user automatically becomes the administrator.
3. Click your profile picture → **Settings → API Keys → Create new secret
   key**. Save the **access key** and **secret key**.

✔ **Checkpoint:** you can log in, and you have two long strings.

---

## 4. Create the project and the queue

SpeakEZ's job coordinator is configured (in its `config.ini`) to use a
ClearML **project** named `SpeakEZ` and a **queue** named `speakez`. Create
both now (names must match exactly; if you prefer different names, change
`project_name` and `queue_name` in `oral-transcription/config.ini` to
match):

1. In the ClearML web UI, go to **Projects → + New Project**. Name it
   `SpeakEZ`, description "Oral history transcription jobs". Create it.
2. Open the project, then go to **Orchestration → Queues** (left menu) →
   **+ New queue**. Name it `speakez`. Leave all other settings at their
   defaults. Create it.

✔ **Checkpoint:** under **Projects** you see `SpeakEZ`, and under
**Orchestration → Queues** you see `speakez` with zero tasks.

---

## 5. Register the five template jobs

When a user starts a job on the website, the coordinator **copies** one of
five pre-made "template" tasks. These templates do not exist on a fresh
ClearML server — you create them **once** by running the seeding commands
from `SETUP.md` Section 8.4 (on the worker machine, after it is configured):

```bash
docker compose exec app bash /workspace/create-templates/cat-talk-verbatimizer.sh
docker compose exec app bash /workspace/create-templates/cat-talk-riskalyzer.sh
docker compose exec app bash /workspace/create-templates/cat-talk-ohmsifier.sh
docker compose exec app bash /workspace/create-templates/cat-talk-synchronifier.sh
docker compose exec app bash /workspace/create-templates/cat-talk-custom.sh
```

Each ends with an error about a missing template dataset — **that is
normal**. The template task itself is saved *before* that error, which is
all the coordinator needs (it appears in the project even though it shows a
failed/aborted status).

✔ **Checkpoint:** in the ClearML web UI, inside the `SpeakEZ` project, you
see five tasks named `verbatimizer_template_v4`,
`riskalyzer_template_v2`, `ohmsifier_template_v0`,
`synchronifier_template_v1`, and `custom_job_template_v1`.

> If a seeding script errors for a different reason (for example it cannot
> reach the storage server), fix that underlying issue and re-run — the
> other templates are unaffected.

---

## 6. About datasets

SpeakEZ uses ClearML **datasets** to register the audio that jobs process.
You do **not** create these by hand: the job coordinator creates them
automatically (its API has a `/create-clearml-dataset` endpoint that the
website calls when a file is uploaded). Each dataset appears in the
`SpeakEZ` project under the **Datasets** tab, named after the interview
collection/file it contains.

The only thing you must provide is working **file storage credentials** in
the `clearml.conf` of both the job coordinator and the workers — ClearML
uses them to read the audio and store results. Those are the MinIO/S3
entries shown in `example-configs/*.clearml.conf`.

✔ **Checkpoint (after your first upload):** the `SpeakEZ` project's
**Datasets** tab lists a dataset.

---

## 7. Wire the credentials into SpeakEZ

Put the ClearML server address and your API keys in the `clearml.conf` of
**both** programs (they are identical files):

| File | Where it lives |
|---|---|
| `oral-transcription/clearml.conf` | the job coordinator machine |
| `cat-talk-jobs/clearml.conf` | the worker machine |

For the **free cloud** (Option A) use exactly:

```
api {
    web_server: https://app.clearml.ai
    api_server: https://api.clearml.ai
    credentials {
        "access_key" = "YOUR-ACCESS-KEY"
        "secret_key"  = "YOUR-SECRET-KEY"
    }
}
```

For a **self-hosted** server (Option B):

```
api {
    web_server: http://<server-ip>:8080
    api_server: http://<server-ip>:8008
    credentials {
        "access_key" = "YOUR-ACCESS-KEY"
        "secret_key"  = "YOUR-SECRET-KEY"
    }
}
```

Pre-filled, commented versions of both files ship in
`example-configs/` (with the storage credentials too).

Restart each program after changing its `clearml.conf`
(`docker compose restart`).

---

## 8. Verify the whole ClearML setup

1. **Workers connected:** start the worker (`cat-talk-jobs`), then in the
   ClearML web UI open **Orchestration → Workers**. You should see your
   worker machine listed (it appears as `app:0` or similar, refreshing
   periodically).
2. **Coordinator connected:** from the web server, run
   `curl -s -H "apiKey: YOUR-KEY-A" http://localhost:5050/get-queued-jobs`
   — it should print `[]` (an empty queue). A ClearML error here means the
   coordinator's `clearml.conf` is wrong.
3. **A real task:** upload a recording on the website and start a
   *riskalyzer* job. In ClearML you should see: a new task appear in the
   `SpeakEZ` project, move into the `speakez` queue, get picked up by the
   worker, and finish green.

---

## 9. Troubleshooting

| What you see | What it usually means | What to do |
|---|---|---|
| Workers never appear in the Workers tab | `api_server` address wrong, or port 8008 firewalled | Open `http://<server-ip>:8008` in a browser — you should get a small response (not a timeout). Fix the address/firewall |
| `401 Unauthorized` in worker/coordinator logs | API keys wrong, or keys from a different server | Re-create the keys in the UI and paste again; confirm the server addresses match the same server the keys came from |
| `Queue 'speakez' not found` | Queue not created, or name mismatch | Section 4 — names must match `config.ini` exactly |
| Task created but never leaves the queue | No worker is listening on that queue | Check the worker is running and its `clearml.conf` points at the same server |
| Templates missing when a job starts | Seeding not run (or project mismatch) | Section 5; also confirm `project_name` in the coordinator's `config.ini` matches where the templates were created |
| `Connection refused` to port 8081 from a job | File-server port firewalled | Open port 8081 to the web server and worker machine |
| Server containers keep restarting | Not enough memory | Give the machine at least 8 GB; check `docker compose logs` in `clearml-server` for which container is failing |
