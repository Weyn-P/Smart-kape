<?php
session_start();
if (isset($_SESSION['username'])) {
    header("Location: dashboard.php");
    exit();
}

$notification_msg = '';
$notification_type = 'error';

if (isset($_GET['error'])) {
    $err = $_GET['error'];
    if ($err === 'invalid_credentials') {
        $notification_msg = 'Invalid username or password. Please try again.';
    } elseif ($err === 'empty_fields') {
        $notification_msg = 'Please enter both your username and password.';
    } elseif ($err === 'db_error') {
        $notification_msg = 'System database connection error. Please try again.';
    } else {
        $notification_msg = 'An error occurred during login. Please try again.';
    }
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $notification_msg = 'You have been logged out successfully.';
    $notification_type = 'success';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Login</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-200 font-sans text-slate-900 min-h-screen flex items-center justify-center p-4 antialiased bg-[radial-gradient(at_10%_10%,_#cbd5e1_0px,_transparent_55%),radial-gradient(at_90%_90%,_#94a3b8_0px,_transparent_55%),radial-gradient(at_50%_50%,_#f1f5f9_0px,_transparent_100%)] bg-fixed">

    <!-- Notification Toast (Top Right) -->
    <?php if (!empty($notification_msg)): ?>
        <div id="notification-banner" class="fixed top-6 right-6 z-[100] max-w-sm p-4 rounded-xl border text-sm font-semibold flex items-center gap-3 transition-all duration-500 transform translate-x-[150%] opacity-0 shadow-2xl backdrop-blur-md <?php echo $notification_type === 'success' ? 'bg-emerald-50/95 border-emerald-300 text-emerald-800' : 'bg-rose-50/95 border-rose-300 text-rose-800'; ?>">
            <i class="fa-solid <?php echo $notification_type === 'success' ? 'fa-circle-check text-emerald-600' : 'fa-circle-exclamation text-rose-600'; ?> text-xl flex-shrink-0"></i>
            <span><?php echo htmlspecialchars($notification_msg); ?></span>
        </div>
    <?php endif; ?>

    <main class="w-full max-w-md">
        <!-- Glassmorphism Container Card -->
        <div class="relative overflow-hidden rounded-3xl bg-white/45 border border-white/80 backdrop-blur-2xl p-8 sm:p-10 shadow-xl">
            
            <!-- Brand Header with Custom Transparent Logo -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center mb-3">
                    <img 
                        src="logo.png" 
                        alt="BesKape Logo" 
                        class="h-16 w-auto object-contain drop-shadow-sm transition-transform duration-300 hover:scale-105"
                    >
                </div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">BesKape</h1>
                <p class="text-sm font-semibold text-slate-600 mt-1">Management System</p>
            </div>


            <!-- Login Form -->
            <form action="login_process.php" method="POST" id="loginForm" class="space-y-5">
                <!-- Username Input -->
                <div class="space-y-1.5">
                    <label for="username" class="block text-xs uppercase tracking-wider text-slate-700 font-extrabold">Username</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 pointer-events-none">
                            <i class="fa-solid fa-user text-sm"></i>
                        </span>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            required 
                            autocomplete="username"
                            placeholder="Enter your username"
                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-white/50 border border-white/80 text-slate-900 placeholder-slate-400 text-sm font-medium focus:outline-none focus:bg-white focus:border-slate-900 focus:ring-2 focus:ring-slate-900/10 transition-all duration-200"
                        >
                    </div>
                </div>

                <!-- Password Input -->
                <div class="space-y-1.5">
                    <label for="password" class="block text-xs uppercase tracking-wider text-slate-700 font-extrabold">Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 pointer-events-none">
                            <i class="fa-solid fa-lock text-sm"></i>
                        </span>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="w-full pl-11 pr-11 py-3 rounded-xl bg-white/50 border border-white/80 text-slate-900 placeholder-slate-400 text-sm font-medium focus:outline-none focus:bg-white focus:border-slate-900 focus:ring-2 focus:ring-slate-900/10 transition-all duration-200"
                        >
                        <!-- Password Toggle Button -->
                        <button 
                            type="button" 
                            id="togglePassword" 
                            aria-label="Toggle password visibility"
                            class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-slate-900 transition-colors duration-150 focus:outline-none"
                        >
                            <i class="fa-solid fa-eye text-sm" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Dark Submit Button -->
                <button 
                    type="submit" 
                    name="login" 
                    id="submitBtn"
                    class="w-full mt-2 py-3.5 px-6 rounded-xl font-bold text-sm text-white bg-slate-900 border border-slate-900 shadow-md transition-all duration-300 hover:bg-slate-800 hover:-translate-y-0.5 hover:shadow-xl active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-slate-900/20 cursor-pointer flex items-center justify-center space-x-2"
                >
                    <span>Login</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </button>
            </form>

            <!-- System Info / Footer -->
            <div class="mt-8 text-center border-t border-slate-900/5 pt-5">
                <p class="text-xs font-semibold text-slate-400">BesKape POS v1.0 • All rights reserved</p>
            </div>
        </div>
    </main>

    <!-- Password Visibility Toggle and Notification Fade Script -->
    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        togglePassword.addEventListener('click', () => {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            eyeIcon.classList.toggle('fa-eye', !isPassword);
            eyeIcon.classList.toggle('fa-eye-slash', isPassword);
        });

        // Notification Toast Slide-in and out animation
        const notificationBanner = document.getElementById('notification-banner');
        if (notificationBanner) {
            // Slide in after a brief delay
            setTimeout(() => {
                notificationBanner.classList.remove('translate-x-[150%]', 'opacity-0');
                notificationBanner.classList.add('translate-x-0', 'opacity-100');
            }, 100);

            // Slide out after 1.5 seconds
            setTimeout(() => {
                notificationBanner.classList.remove('translate-x-0', 'opacity-100');
                notificationBanner.classList.add('translate-x-[150%]', 'opacity-0');
                
                setTimeout(() => {
                    notificationBanner.style.display = 'none';
                }, 500); // wait for CSS transition to finish
            }, 1500);
        }

        // Clean up URL parameters so notifications don't re-appear on page refresh
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('msg');
            url.searchParams.delete('error');
            window.history.replaceState(null, '', url.href);
        }
    </script>
</body>
</html>