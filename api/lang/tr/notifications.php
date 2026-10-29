<?php

return [
    'project_request_heading' => 'Yeni proje talebi',
    'project_request_message' => 'Yeni bir proje talebi kararınızı bekliyor.',
    'open_request' => 'Talebi incele',

    'open' => 'Görevi aç',
    'open_project' => 'Projeyi aç',
    'access' => 'Oturumunuz sona erdiyse projeye erişmek için kişisel ProjectBrief bağlantınızı açın.',
    'project' => 'Proje',
    'task' => 'Görev',
    'description' => 'Açıklama özeti',
    'comment' => 'Yorum',
    'test_title' => 'Test bildirimi',
    'test_body' => 'ProjectBrief bildirimleri çalışıyor.',
    'discussion_heading' => 'Proje tartışmasında yeni yorum',
    'discussion_explanation' => ':name projeye yorum yaptı.',
    'open_discussion' => 'Tartışmayı aç',
    'developer' => 'Geliştirici',
    'client' => 'Müşteri',
    'attached_image' => ':actor bir görsel ekledi',
    'attached_files' => ':actor :count dosya ekledi',
    'verify_heading' => 'Bildirim e-postasını doğrulayın',
    'verify_message' => 'Project Brief hesabınızda oturum açarak bu bağlantıyı açın. Bağlantı 30 dakika geçerlidir. Doğrulama tamamlanana kadar önceki adresiniz kullanılır.',
    'verify_text' => 'Project Brief bildirim e-postanızı doğrulayın. Hesabınıza giriş yapıp bu bağlantıyı 30 dakika içinde açın:',
    'verify_action' => 'Adresi doğrula',
    'verify_ignore' => 'Bu değişikliği siz istemediyseniz e-postayı dikkate almayın.',
    'verify_previous' => 'Doğrulama tamamlanana kadar önceki adresiniz kullanılır.',
    'telegram' => [
        'connected' => "✅ Telegram, Project Brief’e başarıyla bağlandı!\n\nProjenizle ilgili bildirimler artık buraya gelecek.",
        'rejected' => "❌ Telegram bağlanamadı.\n\nBağlantının süresi dolmuş veya bağlantı kullanılmış olabilir.\n\nProject Brief → Bildirimler bölümüne dönüp yeni bir bağlantı oluşturun.",
        'welcome' => "👋 Merhaba! Ben Project Brief bildirim botu BriefWeb86Bot.\n\nBildirim almak için projenizi açın, Bildirimler bölümüne gidin ve Telegram’ı bağla seçeneğine dokunun.",
    ],
    'events' => [
        'client' => [
            'task_created' => 'Müşteriden yeni fikir',
            'comment_created' => 'Yeni müşteri yorumu',
            'task_approved' => 'Müşteri görevi onayladı',
        ],
        'admin' => [
            'clarification_requested' => 'Açıklamanız gerekiyor',
            'comment_created' => 'Yeni geliştirici yorumu',
            'task_review' => 'Görev incelemeye hazır',
            'task_done' => 'Görev tamamlandı',
        ],
    ],
    'explanations' => [
        'client' => [
            'task_created' => ':name, :project projesine yeni bir fikir ekledi.',
            'comment_created' => ':name müşteri olarak yorum yaptı:',
            'task_approved' => ':name, :project projesindeki görevi onayladı.',
        ],
        'admin' => [
            'clarification_requested' => 'Geliştirici bu görevle ilgili açıklamanızı bekliyor.',
            'comment_created' => ':name geliştirici olarak yorum yaptı:',
            'task_review' => 'Bu görev üzerindeki çalışma incelemeniz için hazır.',
            'task_done' => 'Bu görev tamamlandı.',
        ],
    ],
    'next' => [
        'client' => [
            'task_created' => 'Fikri incelemek için görevi açın.',
            'comment_created' => 'Müşteriye yanıt vermek için görevi açın.',
            'task_approved' => 'Onaylanan kapsamı görmek için görevi açın.',
        ],
        'admin' => [
            'clarification_requested' => 'Görevi açıp geliştiriciye yanıt verin.',
            'comment_created' => 'Geliştiriciye yanıt vermek için görevi açın.',
            'task_review' => 'Sonucu kontrol edin ve değişiklik gerekiyorsa yorum yapın.',
            'task_done' => 'Tamamlanan çalışmayı görmek için görevi açın.',
        ],
    ],
];
