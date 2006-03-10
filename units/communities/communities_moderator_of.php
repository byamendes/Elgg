<?php

	// Given a user ID as a parameter, will display a list of communities

	$url = url;
	
	if (isset($parameter[0])) {

		$user_id = (int) $parameter[0];
		
		$result = db_query("select users.* from users
									where users.owner = $user_id and users.user_type = 'community'");
									
		$body = <<< END
	<div class="networktable">
	<table>
		<tr>
END;
		$i = 1;
		if (sizeof ($result) > 0) {
			
			$icon = "default.png";
			$defaulticonparams = @getimagesize(path . "_icons/data/default.png");
			
			foreach($result as $key => $info) {
				list($width, $height, $type, $attr) = $defaulticonparams;
				// $info = $info[0];
				// if ($info->icon != -1) {
					$icon = db_query("select filename from icons where ident = " . $info->icon);
					if (sizeof($icon) == 1) {
						$icon = $icon[0]->filename;
						if (!(list($width, $height, $type, $attr) = @getimagesize(path . "_icons/data/" . $icon))) {
							$icon = "default.png";
							list($width, $height, $type, $attr) = $defaulticonparams;
						}
					}
				// }
				
				if (sizeof($parameter[1]) > 4) {
					$width = round($width / 2);
					$height = round($height / 2);
				}
				$friends_username = stripslashes($info->username);
				$friends_name = htmlentities(stripslashes($info->name));
				// $friends_menu = run("users:infobox:menu",array($info->ident));
				$body .= <<< END
		<td>
			<p>
			<a href="{$url}{$friends_username}/">
			<img src="{$url}_icons/data/{$icon}" width="{$width}" height="{$height}" alt="{$friends_name}" border="0" /></a><br />
			<span class="userdetails">
				{$friends_name}
			</span>
			</p>
		</td>
END;
				if ($i % 5 == 0) {
					$body .= "</tr><tr>";
				}
				$i++;
			}
		} else {
			if ($user_id == $_SESSION['userid']) {
				$body .= "<td><p>". gettext("You don't own any communities. Why not create one?") ."</p></td>";
			} else {
				$body .= "<td><p>". gettext("This user is not currently moderating any communities.") ."</p></td>";
			}
		}
		$body .= <<< END
	</tr>
	</table>
	</div>
END;


		$run_result = $body;

	}

?>