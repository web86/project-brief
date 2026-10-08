<?php

return [
    'open' => 'Open task',
    'open_project' => 'Open project',
    'access' => 'To access the project, open your personal ProjectBrief link if your session has ended.',
    'project' => 'Project',
    'task' => 'Task',
    'description' => 'Description excerpt',
    'comment' => 'Comment',
    'test_title' => 'Test notification',
    'test_body' => 'ProjectBrief notifications are working.',
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
