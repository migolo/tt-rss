<?php
class Pref_Users extends Handler_Administrative {
		function csrf_ignore($method) {
			$csrf_ignored = array("index");

			return array_search($method, $csrf_ignored) !== false;
		}

		function edit() {
			global $access_level_names;

			$id = (int)clean($_REQUEST["id"]);

			$sth = $this->pdo->prepare("SELECT id, login, access_level, email FROM ttrss_users WHERE id = ?");
			$sth->execute([$id]);

			if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
				print json_encode([
						"user" => $row,
						"access_level_names" => $access_level_names
					]);
			} else {
				print json_encode(["error" => "USER_NOT_FOUND"]);
			}
		}

		function userdetails() {
			$id = (int) clean($_REQUEST["id"]);

			$sth = $this->pdo->prepare("SELECT login,
				".SUBSTRING_FOR_DATE."(last_login,1,16) AS last_login,
				access_level,
				(SELECT COUNT(int_id) FROM ttrss_user_entries
					WHERE owner_uid = id) AS stored_articles,
				".SUBSTRING_FOR_DATE."(created,1,16) AS created
				FROM ttrss_users
				WHERE id = ?");
			$sth->execute([$id]);

			if ($row = $sth->fetch()) {
				print "<table width='100%'>";

				$last_login = TimeHelper::make_local_datetime(
					$row["last_login"], true);

				$created = TimeHelper::make_local_datetime(
					$row["created"], true);

				$stored_articles = $row["stored_articles"];

				print "<tr><td>".__('Registered')."</td><td>$created</td></tr>";
				print "<tr><td>".__('Last logged in')."</td><td>$last_login</td></tr>";

				$sth = $this->pdo->prepare("SELECT COUNT(id) as num_feeds FROM ttrss_feeds
					WHERE owner_uid = ?");
				$sth->execute([$id]);
				$row = $sth->fetch();
				$num_feeds = $row["num_feeds"];

				print "<tr><td>".__('Subscribed feeds count')."</td><td>$num_feeds</td></tr>";
				print "<tr><td>".__('Stored articles')."</td><td>$stored_articles</td></tr>";

				print "</table>";

				print "<h1>".__('Subscribed feeds')."</h1>";

				$sth = $this->pdo->prepare("SELECT id,title,site_url FROM ttrss_feeds
					WHERE owner_uid = ? ORDER BY title");
				$sth->execute([$id]);

				print "<ul class=\"panel panel-scrollable list list-unstyled\">";

				while ($line = $sth->fetch()) {

					$icon_file = ICONS_URL."/".$line["id"].".ico";

					if (file_exists($icon_file) && filesize($icon_file) > 0) {
						$feed_icon = "<img class=\"icon\" src=\"$icon_file\">";
					} else {
						$feed_icon = "<img class=\"icon\" src=\"images/blank_icon.gif\">";
					}

					print "<li>$feed_icon&nbsp;<a href=\"".$line["site_url"]."\">".$line["title"]."</a></li>";

				}

				print "</ul>";


			} else {
				print "<h1>".__('User not found')."</h1>";
			}

		}

		function editSave() {
			$login = clean($_REQUEST["login"]);
			$uid = clean($_REQUEST["id"]);
			$access_level = (int) clean($_REQUEST["access_level"]);
			$email = clean($_REQUEST["email"]);
			$password = clean($_REQUEST["password"]);

			// no blank usernames
			if (!$login) return;

			// forbid renaming admin
			if ($uid == 1) $login = "admin";

			if ($password) {
				$salt = substr(bin2hex(get_random_bytes(125)), 0, 250);
				$pwd_hash = encrypt_password($password, $salt, true);
				$pass_query_part = "pwd_hash = ".$this->pdo->quote($pwd_hash).",
					salt = ".$this->pdo->quote($salt).",";
			} else {
				$pass_query_part = "";
			}

			$sth = $this->pdo->prepare("UPDATE ttrss_users SET $pass_query_part login = LOWER(?),
				access_level = ?, email = ?, otp_enabled = false WHERE id = ?");
			$sth->execute([$login, $access_level, $email, $uid]);

		}

		function remove() {
			$ids = explode(",", clean($_REQUEST["ids"]));

			foreach ($ids as $id) {
				if ($id != $_SESSION["uid"] && $id != 1) {
					$sth = $this->pdo->prepare("DELETE FROM ttrss_tags WHERE owner_uid = ?");
					$sth->execute([$id]);

					$sth = $this->pdo->prepare("DELETE FROM ttrss_feeds WHERE owner_uid = ?");
					$sth->execute([$id]);

					$sth = $this->pdo->prepare("DELETE FROM ttrss_users WHERE id = ?");
					$sth->execute([$id]);
				}
			}
		}

		function add() {
			$login = clean($_REQUEST["login"]);
			$tmp_user_pwd = make_password();
			$salt = substr(bin2hex(get_random_bytes(125)), 0, 250);
			$pwd_hash = encrypt_password($tmp_user_pwd, $salt, true);

			if (!$login) return; // no blank usernames

			if (!UserHelper::find_user_by_login($login)) {

				$sth = $this->pdo->prepare("INSERT INTO ttrss_users
					(login,pwd_hash,access_level,last_login,created, salt)
					VALUES (LOWER(?), ?, 0, null, NOW(), ?)");
				$sth->execute([$login, $pwd_hash, $salt]);

				if ($new_uid = UserHelper::find_user_by_login($login)) {

					print T_sprintf("Added user %s with password %s",
						$login, $tmp_user_pwd);

				} else {
					print T_sprintf("Could not create user %s", $login);
				}
			} else {
				print T_sprintf("User %s already exists.", $login);
			}
		}

		function resetPass() {
			UserHelper::reset_password(clean($_REQUEST["id"]));
		}

		function index() {

			global $access_level_names;

			$user_search = clean($_REQUEST["search"] ?? "");

			if (array_key_exists("search", $_REQUEST)) {
				$_SESSION["prefs_user_search"] = $user_search;
			} else {
				$user_search = ($_SESSION["prefs_user_search"] ?? "");
			}

			$sort = clean($_REQUEST["sort"] ?? "");

			if (!$sort || $sort == "undefined") {
				$sort = "login";
			}

			$sort = $this->_validate_field($sort,
				["login", "access_level", "created", "num_feeds", "created", "last_login"], "login");

			if ($sort != "login") $sort = "$sort DESC";

			?>

			<div dojoType='dijit.layout.BorderContainer' gutters='false'>
				<div style='padding : 0px' dojoType='dijit.layout.ContentPane' region='top'>
					<div dojoType='fox.Toolbar'>

						<div style='float : right'>
							<input dojoType='dijit.form.TextBox' id='user_search' size='20' type='search'
								value="<?= htmlspecialchars($user_search) ?>">
							<button dojoType='dijit.form.Button' onclick='Users.reload()'>
								<?= __('Search') ?>
							</button>
						</div>

						<div dojoType='fox.form.DropDownButton'>
							<span><?= __('Select') ?></span>
							<div dojoType='dijit.Menu' style='display: none'>
								<div onclick="Tables.select('users-list', true)"
									dojoType='dijit.MenuItem'><?= __('All') ?></div>
								<div onclick="Tables.select('users-list', false)"
									dojoType='dijit.MenuItem'><?= __('None') ?></div>
								</div>
							</div>

						<button dojoType='dijit.form.Button' onclick='Users.add()'>
							<?= __('Create user') ?>
						</button>

						<button dojoType='dijit.form.Button' onclick='Users.removeSelected()'>
							<?= __('Remove') ?>
						</button>

						<button dojoType='dijit.form.Button' onclick='Users.resetSelected()'>
							<?= __('Reset password') ?>
						</button>

						<?php PluginHost::getInstance()->run_hooks(PluginHost::HOOK_PREFS_TAB_SECTION, "prefUsersToolbar") ?>

					</div>
				</div>
				<div style='padding : 0px' dojoType='dijit.layout.ContentPane' region='center'>

					<table width='100%' class='users-list' id='users-list'>

						<tr class='title'>
							<td align='center' width='5%'> </td>
							<td width='20%'><a href='#' onclick="Users.reload('login')"><?= ('Login') ?></a></td>
							<td width='20%'><a href='#' onclick="Users.reload('access_level')"><?= ('Access Level') ?></a></td>
							<td width='10%'><a href='#' onclick="Users.reload('num_feeds')"><?= ('Subscribed feeds') ?></a></td>
							<td width='20%'><a href='#' onclick="Users.reload('created')"><?= ('Registered') ?></a></td>
							<td width='20%'><a href='#' onclick="Users.reload('last_login')"><?= ('Last login') ?></a></td>
						</tr>

						<?php
							$sth = $this->pdo->prepare("SELECT
									tu.id,
									login,access_level,email,
									".SUBSTRING_FOR_DATE."(last_login,1,16) as last_login,
									".SUBSTRING_FOR_DATE."(created,1,16) as created,
									(SELECT COUNT(id) FROM ttrss_feeds WHERE owner_uid = tu.id) AS num_feeds
								FROM
									ttrss_users tu
								WHERE
									(:search = '' OR login LIKE :search) AND tu.id > 0
								ORDER BY $sort");
							$sth->execute([":search" => $user_search ? "%$user_search%" : ""]);

							while ($row = $sth->fetch()) { ?>

								<tr data-row-id='<?= $row["id"] ?>' onclick='Users.edit(<?= $row["id"] ?>)' title="<?= __('Click to edit') ?>">
									<td align='center'>
										<input onclick='Tables.onRowChecked(this); event.stopPropagation();'
										dojoType='dijit.form.CheckBox' type='checkbox'>
									</td>

									<td><i class='material-icons'>person</i> <?= htmlspecialchars($row["login"]) ?></td>
									<td><?= $access_level_names[$row["access_level"]] ?></td>
									<td><?= $row["num_feeds"] ?></td>
									<td><?= TimeHelper::make_local_datetime($row["created"], false) ?></td>
									<td><?= TimeHelper::make_local_datetime($row["last_login"], false) ?></td>
								</tr>
						<?php } ?>
					</table>
				</div>
				<?php PluginHost::getInstance()->run_hooks(PluginHost::HOOK_PREFS_TAB, "prefUsers") ?>
			</div>
		<?php
	}

	private function _validate_field($string, $allowed, $default = "") {
			if (in_array($string, $allowed))
				return $string;
			else
				return $default;
		}

}
