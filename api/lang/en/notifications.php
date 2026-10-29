<?php

return [
    'project_request_heading' => 'New project request',
    'project_request_message' => 'A new project request is waiting for your decision.',
    'open_request' => 'Review request',

    'open' => 'Open task',
    'open_project' => 'Open project',
    'access' => 'To access the project, open your personal ProjectBrief link if your session has ended.',
    'project' => 'Project',
    'task' => 'Task',
    'description' => 'Description excerpt',
    'comment' => 'Comment',
    'test_title' => 'Test notification',
    'test_body' => 'ProjectBrief notifications are working.',
    'discussion_heading' => 'New project discussion comment',
    'discussion_explanation' => ':name commented on the project.',
    'open_discussion' => 'Open discussion',
    'developer' => 'Developer',
    'client' => 'Client',
    'attached_image' => ':actor attached an image',
    'attached_files' => ':actor attached :count file|:actor attached :count files',
    'verify_heading' => 'Verify notification email',
    'verify_message' => 'Open this link while signed into your Project Brief account. The link expires in 30 minutes. Your previous address stays active until verification.',
    'verify_text' => 'Verify your Project Brief notification email. Sign into your account and open this link within 30 minutes:',
    'verify_action' => 'Verify address',
    'verify_ignore' => 'If you did not request this change, ignore this email.',
    'verify_previous' => 'Your previous address stays active until verification.',
    'telegram' => [
        'connected' => "✅ Telegram successfully connected to Project Brief!\n\nNotifications about your project will now arrive here.",
        'rejected' => "❌ Could not connect Telegram.\n\nThe link may have expired or already been used.\n\nGo back to Project Brief → Notifications and create a new connection link.",
        'welcome' => "👋 Hi! I'm BriefWeb86Bot, the Project Brief notification bot.\n\nTo receive notifications, open your project, go to Notifications and select Connect Telegram.",
    ],
    'events' => [
        'client' => [
            'task_created' => 'New client idea',
            'comment_created' => 'New client comment',
            'task_approved' => 'Task approved by client',
        ],
        'admin' => [
            'clarification_requested' => 'Clarification needed',
            'comment_created' => 'New developer comment',
            'task_review' => 'Task ready for review',
            'task_done' => 'Task completed',
        ],
    ],
    'explanations' => [
        'client' => [
            'task_created' => ':name added a new idea in project :project.',
            'comment_created' => ':name left a client comment:',
            'task_approved' => ':name approved the task in project :project.',
        ],
        'admin' => [
            'clarification_requested' => 'The developer needs your clarification on this task.',
            'comment_created' => ':name left a developer comment:',
            'task_review' => 'Work on this task is ready for your review.',
            'task_done' => 'This task has been completed.',
        ],
    ],
    'next' => [
        'client' => [
            'task_created' => 'Open the task to review the idea.',
            'comment_created' => 'Open the task to reply to the client.',
            'task_approved' => 'Open the task to view the approved scope.',
        ],
        'admin' => [
            'clarification_requested' => 'Open the task and answer the developer.',
            'comment_created' => 'Open the task to reply to the developer.',
            'task_review' => 'Check the result and leave a comment if changes are needed.',
            'task_done' => 'Open the task to view the completed work.',
        ],
    ],
];
