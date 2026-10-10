<?php
header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/adminpage/runfromhere.html', true, 302);
exit;
