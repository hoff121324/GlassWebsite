<?php
	session_start();

	if(!isset($_SESSION['csrftoken'])) {
		$_SESSION['csrftoken'] = mt_rand();
	}

	if(isset($_POST['csrftoken']) && $_POST['csrftoken'] == $_SESSION['csrftoken']) {
		session_destroy();

		if(isset($_POST['redirect'])) {
			header("Location: " . $_POST['redirect']);
		} else {
			header("Location: " . "/index.php");
		}
		die();
	}
	echo("Cross site request forgery attempt blocked.  Please click below to confirm your Logout.");
?>
<form id="logoutForm" action="/logout.php" method="post">
	<input type="hidden" name="csrftoken" value="<?php echo($_SESSION['csrftoken']); ?>">

	<?php
		if(isset($_POST['redirect'])) { ?>
			<input type="hidden" name="redirect" value="<?php echo(htmlspecialchars($_POST['redirect'])); ?>">
		<?php
		} ?>
	<input type="submit" name="submit" value="Log Out">
</form>
