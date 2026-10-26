<?php

return [
    'open' => '打开任务',
    'open_project' => '打开项目',
    'access' => '如果会话已结束，请打开您的个人 ProjectBrief 链接以访问项目。',
    'project' => '项目',
    'task' => '任务',
    'description' => '描述摘要',
    'comment' => '评论',
    'test_title' => '测试通知',
    'test_body' => 'ProjectBrief 通知工作正常。',
    'discussion_heading' => '项目讨论有新评论',
    'discussion_explanation' => ':name 评论了此项目。',
    'open_discussion' => '打开讨论',
    'developer' => '开发者',
    'client' => '客户',
    'attached_image' => ':actor 添加了一张图片',
    'attached_files' => ':actor 添加了 :count 个文件',
    'verify_heading' => '验证通知邮箱',
    'verify_message' => '请登录您的 Project Brief 账号后打开此链接。链接有效期为 30 分钟。验证前仍使用原邮箱。',
    'verify_text' => '请验证您的 Project Brief 通知邮箱。登录账号后，请在 30 分钟内打开此链接：',
    'verify_action' => '验证邮箱',
    'verify_ignore' => '如果您未申请此变更，请忽略此邮件。',
    'verify_previous' => '验证前仍使用原邮箱。',
    'telegram' => [
        'connected' => "✅ Telegram 已成功连接到 Project Brief！\n\n今后您的项目通知将发送到这里。",
        'rejected' => "❌ 无法连接 Telegram。\n\n此链接可能已过期或已使用。\n\n请返回 Project Brief → 通知，创建新的连接链接。",
        'welcome' => "👋 您好！我是 Project Brief 通知机器人 BriefWeb86Bot。\n\n要接收通知，请打开项目，前往“通知”，然后选择“连接 Telegram”。",
    ],
    'events' => [
        'client' => [
            'task_created' => '客户添加了新创意',
            'comment_created' => '客户有新评论',
            'task_approved' => '客户已确认任务',
        ],
        'admin' => [
            'clarification_requested' => '需要您补充说明',
            'comment_created' => '开发者有新评论',
            'task_review' => '任务已提交审核',
            'task_done' => '任务已完成',
        ],
    ],
    'explanations' => [
        'client' => [
            'task_created' => ':name 在项目 :project 中添加了新创意。',
            'comment_created' => ':name 作为客户发表了评论：',
            'task_approved' => ':name 确认了项目 :project 中的任务。',
        ],
        'admin' => [
            'clarification_requested' => '开发者需要您对该任务补充说明。',
            'comment_created' => ':name 作为开发者发表了评论：',
            'task_review' => '此任务的工作成果已准备好，请您审核。',
            'task_done' => '此任务已完成。',
        ],
    ],
    'next' => [
        'client' => [
            'task_created' => '打开任务以查看创意。',
            'comment_created' => '打开任务以回复客户。',
            'task_approved' => '打开任务以查看已确认的工作范围。',
        ],
        'admin' => [
            'clarification_requested' => '请打开任务并回复开发者。',
            'comment_created' => '打开任务以回复开发者。',
            'task_review' => '请检查结果，如需修改，请发表评论。',
            'task_done' => '打开任务以查看已完成的工作。',
        ],
    ],
];
