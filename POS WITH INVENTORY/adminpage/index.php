<?php
header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/runfromhere.html', true, 302);
exit;
