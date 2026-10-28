<?php
require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Online Judge System'; ?> - Đề Tài 20</title>
    
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS & JS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Font Awesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --bg-body: #0a0e17;
            --bg-card: rgba(17, 24, 39, 0.85);
            --bg-surface: #111827;
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --primary: #6366f1;
            --primary-grad: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #d946ef 100%);
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-yellow: #f59e0b;
            --accent-cyan: #06b6d4;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(217, 70, 239, 0.10) 0px, transparent 50%);
            background-attachment: fixed;
        }

        /* Navbar */
        .navbar-custom {
            background: rgba(10, 14, 23, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 0;
        }

        .brand-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--primary-grad);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            margin-right: 12px;
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.4);
        }

        .nav-link {
            color: var(--text-muted) !important;
            font-weight: 500;
            padding: 8px 16px !important;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .nav-link:hover, .nav-link.active {
            color: #fff !important;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-link.active {
            color: #818cf8 !important;
        }

        /* Card */
        .custom-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            backdrop-filter: blur(12px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }

        /* Badges */
        .badge-easy { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-medium { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-hard { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }

        /* Verdict Badges */
        .verdict-AC { background: #10b981; color: #fff; }
        .verdict-WA { background: #ef4444; color: #fff; }
        .verdict-TLE { background: #f59e0b; color: #000; }
        .verdict-RE { background: #a855f7; color: #fff; }
        .verdict-CE { background: #f97316; color: #fff; }
        .verdict-SE { background: #64748b; color: #fff; }
        .verdict-PENDING { background: #6366f1; color: #fff; }

        /* Code styling */
        pre, code {
            font-family: 'JetBrains Mono', monospace;
        }

        /* Footer */
        footer {
            margin-top: auto;
            border-top: 1px solid var(--border-color);
            padding: 24px 0;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
