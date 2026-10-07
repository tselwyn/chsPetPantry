<?php
    // Comment for assignment -Madi
    // Template for new VMS pages. Base your new page on this one

    // Make session information accessible, allowing us to associate
    // data with the logged-in user.
    session_cache_expire(30);
    session_start();
    
    ini_set("display_errors",1);
    error_reporting(E_ALL);

    // redirect to index if already logged in
    if (isset($_SESSION['_id'])) {
        header('Location: index.php');
        die();
    }

    $badLogin = false;
    $archivedAccount = false;

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        require_once('include/input-validation.php');
        $ignoreList = array('password');
        $args = sanitize($_POST, $ignoreList);
        $required = array('username', 'password');
        if (wereRequiredFieldsSubmitted($args, $required)) {
            require_once('domain/Person.php');
            require_once('database/dbPersons.php');

            $username = strtolower($args['username']);
            $password = $args['password'];
            $user = retrieve_person($username);

            /*var_dump($user->get_access_level());
            var_dump($user->get_type());
            die();*/

            if (!$user) {
                $badLogin = true;
            } else if ($user->get_status() !== "Active") {
                // If the user is archived, block login
                $archivedAccount = true;
            } else if (password_verify($password, $user->get_password())) {
                $_SESSION['logged_in'] = true;
                $_SESSION['access_level'] = $user->get_access_level();
                $role = $user->get_type();
                /*$roleMap = [
                    "superadmin" => 3,
                    "admin" => 2,
                    "inventory_counter" => 1
                ];
                $_SESSION['access_level'] = $roleMap[$role] ?? 1;*/
                $_SESSION['f_name'] = $user->get_first_name();
                $_SESSION['l_name'] = $user->get_last_name();

                
                $_SESSION['type'] = $user->get_type();
                $_SESSION['_id'] = $user->get_id();
                $_SESSION['_personId'] = $user->get_personId();
                
                 //hard code root privileges
                 if ($user->get_id() == 'vmsroot') {
                    $_SESSION['access_level'] = 3;
		            $_SESSION['locked'] = false;
                    header('Location: index.php');
               }
                else {
                    header('Location: index.php');
                    die();
                }
                die();
            } else {
                $badLogin = true;
            }
        }
    }
    //<p>Or <a href="register.php">register as a new volunteer</a>!</p>
    //Had this line under login button, took user to register page
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;700&display=swap" rel="stylesheet">
        <style>
            /* Same font as the dashboard (index.php) */
            * { font-family: Quicksand, sans-serif; }
        </style>
        <title>CHS Pet Pantry | Log In</title>
    </head>
    <!-- CHS dark teal background, matching the dashboard navbar and footer -->
    <body class="min-h-screen bg-[#1f5968] flex flex-col items-center justify-center px-4 py-10">

        <!-- Login card -->
        <main class="w-full max-w-md bg-white rounded-xl shadow-xl px-8 py-10 sm:px-10">

            <!-- CHS logo -->
            <div class="flex justify-center mb-6">
                <img src="images/chs-logo.svg"
                     alt="Culpeper Humane Society"
                     class="w-56 h-auto">
            </div>

            <h1 class="text-2xl font-bold text-[#1f5968] text-center">Pet Pantry Log In</h1>
            <p class="mt-1 mb-6 text-gray-600 text-center">Keeping pets fed and families together.</p>

            <form method="post">
                <?php
                    if ($badLogin) {
                        echo '<div role="alert" class="mb-4 p-3 rounded-lg border-l-4 border-red-600 bg-red-50 text-red-800 text-sm font-medium">No login with that username and password combination currently exists.</div>';
                    }
                    if ($archivedAccount) {
                        echo '<div role="alert" class="mb-4 p-3 rounded-lg border-l-4 border-red-600 bg-red-50 text-red-800 text-sm font-medium">This account has either been archived or not yet approved by management. For help, notify your administrator.</div>';
                    }
                    if (isset($_GET['registerSuccess'])) {
                        echo '<div role="status" class="mb-4 p-3 rounded-lg border-l-4 border-green-600 bg-green-50 text-green-800 text-sm font-medium">Registration Successful! Please login below.</div>';
                    }
                ?>
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-1" for="username">Username</label>
                    <input class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50 focus:outline-none focus:border-[#50bfd3] focus:ring-2 focus:ring-[#50bfd3]" type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" required>
                </div>
                <div class="mb-2">
                    <label class="block text-gray-700 font-semibold mb-1" for="password">Password</label>
                    <input class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50 focus:outline-none focus:border-[#50bfd3] focus:ring-2 focus:ring-[#50bfd3]" type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                </div>
                <div class="flex justify-end mb-6">
                    <a href="forgotPassword.php" class="text-[#1f5968] text-sm font-medium hover:underline">Forgot password?</a>
                </div>
                <button class="cursor-pointer w-full bg-[#1f5968] hover:bg-[#17444f] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#50bfd3] text-white font-semibold py-3 rounded-lg transition duration-300">Log In</button>
            </form>

            <!-- Sign Up Section -->
            <!--   <p class="mt-6 text-center text-gray-700">
                Don’t have an account?
                <a href="VolunteerRegister.php" class="text-[#1f5968] font-semibold hover:underline">Sign Up Now</a>
            </p>-->

        </main>

        <p class="mt-6 max-w-md text-center text-sm text-[#cdeef4]">
            Need an account? Contact a Pet Pantry volunteer.
        </p>

    </body>
</html>
