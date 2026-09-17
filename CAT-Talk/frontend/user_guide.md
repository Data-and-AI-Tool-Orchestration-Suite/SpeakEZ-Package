# User Guide: CAT-Talk

## Background

CAT-Talk, developed by the University of Kentucky's Center for Applied AI, is a secure web-based platform that streamlines speech-to-text transcription and speaker-labeling (diarization), 
addressing a growing need for accurate, efficient documentation in healthcare and related fields. This self-service tool enables users of all technical backgrounds to quickly generate, edit, and analyze transcripts 
with ease using AI. CAT-Talk significantly reduces the burden of manual documentation. LLM-powered tools are integrated into the system allowing users to summarize transcripts or create their own custom jobs 
tailored to meet each project’s unique needs. For those concerned about the security of private data, CAT-Talk offers a solution. This system operates on UK-owned, NIST-compliant infrastructure, and Azure, keeping your data secure.  

**Models** within CAT-Talk currently include OpenAI's [whisper-large-v3](https://huggingface.co/openai/whisper-large-v3), [PyAnnote's Diarization model](https://github.com/pyannote/pyannote-audio), [Meta's LLaMA 3.1 70B](https://huggingface.co/meta-llama/Llama-3.1-70B), and [Deepseek-R1](https://github.com/deepseek-ai/DeepSeek-R1) (as of July 2025).

***Version Notice*:** This guide is for the **beta** version of CAT-Talk, available as of December 2024. 
Keep in mind the platform is in early stages and regularly updated. We always encourage manual review of outputs and welcome you to contact us if you run into any issues. 

***Citation Notice*:** By Using CAT-Talk, you agree to appropriately cite the tool in your research, products, or efforts.
Here is the paper on CAT-Talk for your reference: https://arxiv.org/pdf/2409.15378

***REMINDER*:** This document is always evolving, if you have questions, please reach out to CAAI for assistance. 

## Tenants: Custom Appearance & Job Access

CAT-Talk supports multiple tenants, allowing organizations or groups to have their own customized experience within the platform. Each tenant can:
- Set a specific appearance for the website, including custom colors and branding.
- Control which jobs and features are available to their users, enabling tailored workflows and permissions.

This means that when you log in, the look and available tools may differ depending on your tenant. Administrators can configure these settings to best fit the needs of their team or organization.

## How CAT-Talk Works

For more information on CAT-Talk, visit our webpage:
[CAT-Talk- CAAI](https://caai.ai.uky.edu/services/cat-talk/)

## Types of Users

There are two types of user permissions within CAT-Talk: Administrator, General, and Collaborator-Specific.
-   Administrators are CAAI technical staff.
-   General users can generate, edit, analyze, and query an LLM with transcripts.  

***User Notice***: This guide covers how to use the CAT-Talk self-service tool. this guide is for **General** users.

## Account Creation

CAT-Talk is online and available at:
[https://data.ai.uky.edu](https://data.ai.uky.edu/)

CAT-Talk is accessible via CILogon. CILogon is our federated identity management gateway. When you open CAT-Talk, you must choose your instutituion or provider from the list, then you can enter your existing credentials to access the system. There's no need to create another account, you can access the system using your LinkBlue ID, Microsoft 365 account, Google account, or GitHub account, etc. 

If you do not see your Identity Provider on the list, please reach out to ai@uky.edu. 

## Account Access, Logging In
 
-   Go to [https://data.ai.uky.edu](https://data.ai.uky.edu/)

-   Select CAT-Talk from the Data.AI dashboard

    ![CAT-Talk dashboard logo](/img/UserGuideImages/CAT-TalkDashboardLogo.png")

-   Sign in with your credentials through CILogon (If you're affiliated with UK, this is
    your LinkBlue username and password.)

-   When CAT-Talk opens, you will see the Projects page. This page
    appears first by default.

    ![Projects page seen upon logging in](/img/UserGuideImages/ProjectsPage.png")

**Note:** The first time you log into the system, you will be prompted to confirm your willingness to cite your use of this tool. 


# Workflow

## Creating a Project

To organize your efforts or establish a shared collaborative space, you can
create projects within CAT-Talk. Projects are used to group uploaded files and any associated output including transcripts and jobs.

Create a project by clicking the “Create New Project” button on the *Projects* page.

![Create Project Button](/img/UserGuideImages/CreateProjectButton.png)

Name your project and it will appear as a card on the *Projects* page. You may create as many projects as you need, but **please note** you can also group files within projects using collections. 

### Inviting Collaborators

To invite team members to join you on this project, they will need to have their own Data.AI account established. Data.AI accounts are established, the first time that a user logs into the system. 
In order to invite collaborators as team members within your project, they must first log into CAT-Talk themselves.

1.  Click the Details button

![Project Details Button](/img/UserGuideImages/ProjectCardwButtons.png)

2.  Click on the “Add Member” button to invite users to join the project
    space and collaborate. You must select your team members from the
    list provided. If they do not appear on the list, they do not have
    an established CAT-Talk account.
    
![Add Member Button](/img/UserGuideImages/AddMemberButton.png)

3.  Select (“+”) next your team member’s name from the list. (There are multiple pages, please be sure to click the arrows to check all of the names.) 
    Note, team members will not receive a notification. They will automatically see the name of your project on their *Projects* page.
![Add Members Window](/img/UserGuideImages/MembersWindow.png)

## Uploading Files
To upload your files navigate to your project's dashboard. 

1. Click the "Dashboard" button from the *Projects* page.
![Dashboard Button](/img/UserGuideImages/ProjectCardwButtons.png)

Spaces *cannot* be read, please ensure your collection codes do not contain any spaces, instead please use "-" or "_"

2. Click the Upload File(s) button in the top right corner
![Upload Button](/img/UserGuideImages/UploadButton.png)

3. Select your file(s) from your machine by clicking the Choose Files button. 
Currently, CAT-Talk supports .mp3, .mp4, .mpeg, .mpga, and .m4a file types.

![Upload File(s) Window](/img/UserGuideImages/UploadFile(s)Window.png)

4. Each file is required to have an associated Collection Code. Specify this in the Collection Code text box.
    Collection Codes offer an additional way to group uploaded files. Create Collection Codes within your project to organize files into subgroups. 

5. Ensure Machine Readable
    Before submitting, make sure there are no spaces in your collection code, these will prevent your upload. 

6. Click the Upload Button
    There is an upload indicator that will spin indicating your file is being uploaded to the system. It can take some time for larger files to upload, you are welcome to leave CAT-Talk open in the background and check on the upload status periodically. Please **do not refresh the page, close out the window, or let your machine go to sleep as this will cancel the upload**. 
    
    The time to successfully upload will vary. Generally, the larger the file, the longer it will take. The more files you try to upload at once, the longer it will take the system to process. 
    The system will notify you if a file upload fails. Depending on system traffic and file size, this can take between 10-60 minutes.  If it is taking a while to receive a successful upload notification, let us know (ai@uky.edu) and we can check the internal queue manually.

    You will recieve a success message when the file has been uploaded. 
![Successful Upload View](/img/UserGuideImages/Successful_Upload.png)

#### Limitations
 CAT-Talk's current upload limit is 10 GB per file. If you are trying to upload a video larger than that, you will encounter errors. Instead, you may try converting your file(s) from audio-video to just audio files prior to uploading to reduce file size. 

### Storage Considerations
Currently, the beta version of CAT-Talk operates on CAAI infrastructure, which is shared amongst the center and it's collaborators. We are working on a storage indicator view which will let users know how much storage they have to work with, but for now if you have concerns or if you encounter an upload error related to storage availability, please reach out to ai@uky.edu for more guidance. 

## Running Jobs
To generate transcriptions of your files, modify transcripts, or run analysis navigate to your project's dashboard. On this page you will see your Collections on the left, and any Tunning Processes on the right.

1. Select which Collection you would like to work in
![Collection Button](/img/UserGuideImages/collectionsbutton.png)

This will open the collection dashboard with a table that lists files. 

Clicking the play button in the Transcribe column to generate a transcript. 
After the transcript has been generated, use the Touch-Up feature to review the output and manually make any edits to the trancript where needed. 
The Analyze feature enables you to query an LLM with your transcript.
The Upload Date field details when your files were uplaoded. This is important because CAT-Talk has a 90-day auto-archive feature. "Archive" deletes the audio file but not the generated transcript and results of jobs that have been run. This is to help keep storage limitations in check. 
The Actions column includes a button that allows you to manually archive files. 
The Status column provides an overview of all jobs run or running on a file. The status indicator is either Uploaded or Archived depending on the state of the file. 

![collection dashboard](/img/UserGuideImages/collectiondash.png)

For more information on each of these feaures, please read below.

#### Transcription

To start transcribing your file, click the play button. 
![Play Button](/img/UserGuideImages/transcribe.png)
While the transcript is being generated, you will see a spinning record indicating in-progress work.
![Spinnging Record Button](/img/UserGuideImages/spinningrecord.png)

Note that the touch-up and analyze buttons are greyed out. You cannot clean-up a transcript or run custom jobs until the file's been transcribed. 
![blue/grey play buttons](/img/UserGuideImages/1-2.png)

When transcription is complete, the spinning record will disappear and the touch-up and analyze functions are available for use. 
![all blue play buttons](/img/UserGuideImages/3of3.png)

Manual review is always encouraged. To review your transcript and save it, read the section on Touch-Up below

##### Language Capabilities 
Whisper is capable of transcribing a variety of languages. Check out the list on Whisper's GitHub: [https://github.com/openai/whisper#available-models-and-languages] (https://github.com/openai/whisper#available-models-and-languages)


#### Touch-Up (Edit Transcripts)
After generating a transcript, you can clean it up using the Touch-Up feautre. 

Click the play button within the Touch-Up column to open your trancript. 
On the *Touch-Up* page, you will see the AI-generated transcript on the left with line numbers, time stamps, speaker-labels and transcribed dialogue. 
![Touch-up Page Screenshot](/img/UserGuideImages/Touch-UpPage_new.png)

You can click anywhere within the transcript to make changes. Just be sure to click the "Save" button to ensure your edits are stored in the transcript before navigating elsewhere. 

##### Adjust Speaker Labels
CAT-Talk now uses WhisperX to handle transcription, speaker diarization, and merging automatically. Speaker labels are assigned and merged by WhisperX, and there is no longer any need to adjust speaker labels or use an LLM for merging. If you notice any inaccuracies in speaker labels, please review the transcript manually and make edits as needed using the Touch-Up feature.

For more insight on this approach, please review CAAI's paper: [Toward Automated Clinical Transcriptions](https://arxiv.org/abs/2409.15378).

#### Analyze (Custom LLM-Powered Analysis)
Once your transcript is finalized, you can use the Analyze feature in CAT-Talk to chat with an LLM about your transcript. The Analyze page provides a chat window where you can ask questions about the transcript, request summaries, or get insights directly from the LLM. Simply type your question or prompt in the chat box and the LLM will respond based on the transcript content. There is no longer a custom job system—just ask your questions and get instant answers.

Click the play button within the Analyze column to open your transcript and start chatting with the LLM.

On the Analyze page, you will see the AI-generated transcript on the left, with any edits you made during the touch-up process reflected. On the right, the chat window allows you to interact with the LLM.
![Analyze Page Screenshot](/img/UserGuideImages/analyze_new.png)

##### Check the Status of jobs
After clicking the "Process" button, the button will change to Processing... to indicate that the job is in the queue. Jobs are processed sequentially, so delays may occur based on system load. You may refresh the page. Refreshing the page will *not* affect the job's queue position or delete the custom job. 
![Refresh Button](/img/UserGuideImages/refresh.png)

Once a job is completed, its output will appear on the file’s Analyze page. Scroll down to the Output section to view the results. If the output does not meet expectations, experiment with different prompts. LLMs can be prompted in various ways to achieve unique results, so fine-tuning is encouraged.

You can also check job statuses via the project's dashboard:
Navigate to your project's dashboard by clicking the Dashboard button from the *Projects* page.
![Dashboard Button](/img/UserGuideImages/ProjectCardwButtons.png)
The Running Processes table lists any jobs and associated files currently queued or being executed. This table will be empty if no jobs are queued or once execution is complete.

Open your Collections Dashboard by clicking the collection code, where you will see a Status column for each file showing custom jobs and their progress. When a job has completed running, it will say "completed" in this column and you can then review the output bu downloading the file and it's generated output. 

##### Review a Previously Created Conversation
On the *Analyze* page, you will see a conversation list on the left-hand side. This list displays all previous conversations, including those created by you or your team members (if applicable).
Select a conversation from the list to review its message history, continue the chat, or add new questions to the existing thread. You can also start a new conversation at any time. This makes it easy to revisit earlier analyses, build on previous work, or collaborate with others.
![Conversation List Example](/img/UserGuideImages/joblist_new.png)

#### Downoad Output
Once your transcript is finalized and any custom jobs have been run, you can download all CAT-Talk generated data as a .zip file.  

1. Navigate to the Collection Dashboard by clicking your collection code from the Project Dashboard.
![Collection Button](/img/UserGuideImages/collectionsbutton.png)
2. Select the checkbox next to the file you wish to download. To select all files within the collection, click the checkbox at the top of the column. 
![Select File Box](/img/UserGuideImages/selectfilescheckbox.png)
3. Click the "Download" button at the top of the table.
4. A .zip file will be downloaded to your local machine. 
![Zip File Conents](/img/UserGuideImages/zipfileexcontents.png)

There will be .transcript and .vtt and .txt and .json files for genreated trancripts. Custom job outputs will be available as Report files, viewable in text editors like Notepad++ on Windows. 

**Please Note:** Once you are finished working with a file in CAT-Talk and have downloaded its outputs, we encourage you to archive or delete files within your projects to ensure storage availability for other users and your team. As a remider, all files will be archived 90 days after upload.

# Managing A Project

## Removing Collaborators 

1.  Click the Details button

![Project Details Button](/img/UserGuideImages/ProjectCardwButtons.png)

2.  Select (“-”) next your team member’s name to remove them from the project. Note, team members will not receive a notification. 
    The project, and all associated data stored within CAT-Talk, will automatically disappear from their *Projects* page.

## Deleting a Project 

If you would like to delete a project, you can click the "Delete" button from the project's card on the *Projects* page. A pop-up will appearing connfirming
your choice. 

**Warning:** Deleting a project will delete the shared workspace and all data stored within it from your account and the account's of each member of the project. This cannot be undone.




# Security & Privacy 

All interactions with CAT-Talk (dataset training, model querying, API
requests) are secure because the platform CAT-Talk is hosted on,
Data.AI, is controlled by the University of Kentucky. Unlike other
platforms, such as ChatGPT or Claude, your interactions with the model
and the data you upload are private.

General Access to CAT-Talk (V1 - March 2025) is **not** HIPAA Compliant.
Private, HIPAA compliant instances **are available** on an individual basis
though! If you would like to learn more, please reach out to us:
<u>ai@uky.edu</u>

# Appendix A: Data & Use Tips
## Error Handling
You should see messages pop-up in the right-hand corner of the CAT-Talk interface during uploads, transcription start, and when jobs are created. If for some reason this fails, you will see a red pop-up with some insights. When your process has succesfully been executed, you will see a screen success pop-up breifly appear. 
## Limitations
CAT-Talk's current upload limit is 10 GB per file. If you are trying to upload a video larger than that, you will encounter errors. Instead, you may try converting your file(s) from audio-video to just audio files prior to uploading to reduce file size. 
