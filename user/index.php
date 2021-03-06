<?php
	session_start();

	if(!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
		header("Location: /login.php");
		die();
	}
	include(realpath(dirname(__DIR__) . "/private/header.php"));
	include(realpath(dirname(__DIR__) . "/private/navigationbar.php"));
	require_once(realpath(dirname(__DIR__) . "/private/class/UserManager.php"));
	require_once(realpath(dirname(__DIR__) . "/private/class/AddonManager.php"));
	require_once(realpath(dirname(__DIR__) . "/private/class/BuildManager.php"));
	require_once(realpath(dirname(__DIR__) . "/private/class/BuildObject.php"));
	require_once(realpath(dirname(__DIR__) . "/private/class/BoardObject.php"));
	require_once(realpath(dirname(__DIR__) . "/private/class/NotificationManager.php"));
	require_once(realpath(dirname(__DIR__) . "/private/class/NotificationObject.php"));
	$userObject = UserManager::getCurrent();

	if($userObject === false) {
		header('Location: verifyAccount.php');
		die();
	}

?>
<div class="maincontainer">
	<span style="font-size: 1.5em;">Hey there, <b><?php echo $_SESSION['username']; ?></b></span>
	<table class="userhome">
		<tbody>
			<tr>
				<td style="width: 50%">
					<p>
						<h3>Recent Activity</h3>
						<?php
						$notifications = NotificationManager::getFromBLID($userObject->getBLID(), 0, 10); // TODO NotifcationManager::getFromUser(9789, 10);

						if($notifications !== false) {
							foreach($notifications as $noteId) {
								$noteObject = NotificationManager::getFromId($noteId);
								echo '<div style="background-color: #eee; border-radius: 15px; padding: 15px; margin: 5px;">';
								echo $noteObject->toHTML();
								echo '<br /><span style="font-size: 0.8em;">' . $noteObject->getDate() . '</span>';
								echo '</div>';
							}
						}
						?>
						</p>
				</td>
				<td>
					<p>
						<h3>My Content</h3>
						<?php
						$addons = AddonManager::getFromBLID($userObject->getBLID());

						foreach($addons as $aid) {
							$ao = AddonManager::getFromId($aid);
							$board = $ao->getBoard();
							$html = "";
							if(!$ao->getApproved()) {
								$html = '<img style="width: 1.2em;" src="http://blocklandglass.com/icon/icons32/hourglass.png" alt="Under Review"/> ';
							}
							?>
							<div class="useraddon">
								<?php echo $html ?><a href="/addons/addon.php?id=<?php echo $ao->getId(); ?>"><span style="font-size: 1.2em; font-weight:bold;"><?php echo $ao->getName(); ?></span></a>
								<br />
								<span style="font-size: 0.8em;">
									<a href="#">Update</a> | <a href="#">Edit</a> | <a href="#">Repository</a> | <a href="#">Delete</a>
								</span>
							</div>
							<?php
						}

						?>
						<div class="useraddon" style="text-align:center; background-color: #ccffcc">
							<img style="width: 1.2em;" src="http://blocklandglass.com/icon/icons32/add.png" alt="New"/> <a href="/addons/upload.php">Upload a new Add-on...</a>
						</div>
						<?php
						echo "<hr>";

						$builds = BuildManager::getBuildsFromBLID($userObject->getBLID());
						foreach($builds as $bid) {
							$bo = BuildManager::getFromId($bid);
							?>
							<div class="useraddon">
								<a href="/builds/"><img style="width: 1.2em;" src="http://blocklandglass.com/icon/icons32/bricks.png" /> <span style="font-size: 1.2em; font-weight:bold;"><?php echo $bo->getName(); ?></span></a>
								<br />
								<span style="font-size: 0.8em;">
									<a href="/builds/manage.php?id=<?php echo $bo->getId(); ?>">Manage</a> | <a href="#">Delete</a>
								</span>
							</div>
							<?php
						}
						?>
						<div class="useraddon" style="text-align:center; background-color: #ccffcc">
							<img style="width: 1.2em;" src="http://blocklandglass.com/icon/icons32/add.png" alt="New"/> <a href="/builds/upload.php">Upload a new Build...</a>
						</div>
					</p>
				</td>
			</tr>
		</tbody>
	</table>
</div>

<?php include(realpath(dirname(__DIR__) . "/private/footer.php")); ?>
