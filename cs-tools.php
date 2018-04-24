<?php
	// Cloud Storage Server command line tools.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/str_basics.php";
	require_once $rootpath . "/support/sdk_cloud_storage_server_files.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"userinput" => "="
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Cloud Storage Server command-line tools\n";
		echo "Purpose:  Access Cloud Storage Server APIs directly from the command-line.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] [cmdgroup cmd [cmdoptions]]\n";
		echo "Options:\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " profiles create name=main host=https://127.0.0.1:9892 apikey=abc123xyz-1\n";
		echo "\tphp " . $args["file"] . " -s files create-folder main name=/test\n";

		exit();
	}

	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	// Get the command group.
	$cmdgroups = array(
		"profiles" => "Manage Cloud Storage Server connection profiles",
		"files" => "Access /files"
	);

	$cmdgroup = CLI::GetLimitedUserInputWithArgs($args, "cmdgroup", "Command group", false, "Available command groups:", $cmdgroups, true, $suppressoutput);

	// Get the command.
	switch ($cmdgroup)
	{
		case "profiles":  $cmds = array("list" => "List Cloud Storage Server profiles", "create" => "Create a new Cloud Storage Server profile", "get-info" => "Get information about a Cloud Storage Server profile", "reset-certs" => "Deletes and refreshes stored SSL certs for a Cloud Storage Server instance", "delete" => "Deletes a Cloud Storage Server profile");  break;
		case "files":  $cmds = array("list" => "List files and folders", "create-folder" => "Create a folder", "copy" => "Copy a file or folder", "move" => "Move a file or folder", "rename" => "Rename a file or folder", "list-trash" => "List all items in the trash", "trash" => "Move a file or folder to the trash", "restore" => "Restore a file or folder from the trash", "delete" => "Delete a file or folder", "get-limits" => "Get information about user limits for uploads/downloads", "upload" => "Upload a file or folder", "download" => "Download a file or folder", "list-guests" => "List guests", "create-guest" => "Create a guest", "delete-guest" => "Delete a guest");  break;
	}

	$cmd = CLI::GetLimitedUserInputWithArgs($args, "cmd", "Command", false, "Available commands:", $cmds, true, $suppressoutput);

	// Make sure directories exist.
	@mkdir($rootpath . "/css-profiles", 0700);

	function DisplayResult($result)
	{
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

		exit();
	}

	function CSSProfilesList()
	{
		global $rootpath;

		$result = array("success" => true, "data" => array());
		$path = $rootpath . "/css-profiles";
		$dir = opendir($path);
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== ".." && is_file($path . "/" . $file) && substr($file, -5) == ".json")
				{
					$data = @json_decode(file_get_contents($path . "/" . $file), true);

					if (is_array($data))
					{
						$id = substr($file, 0, -5);

						$result["data"][$id] = array(
							"id" => $id,
							"host" => $data["host"],
							"apikey" => $data["apikey"],
							"created" => $data["created"]
						);
					}
				}
			}

			closedir($dir);
		}

		ksort($result["data"], SORT_NATURAL | SORT_FLAG_CASE);

		return $result;
	}

	function GetCSSProfileName()
	{
		global $suppressoutput, $args;

		if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "profile"))  $cssprofile = CLI::GetUserInputWithArgs($args, "profile", "Cloud Storage Server profile", false, "", $suppressoutput);
		else
		{
			$result = CSSProfilesList();
			if (!$result["success"])  DisplayResult($result);

			$cssprofiles = array();
			foreach ($result["data"] as $id => $cssprofile)
			{
				$cssprofiles[$id] = $cssprofile["host"] . ", " . date("M j, Y", $cssprofile["created"]);
			}
			if (!count($cssprofiles))  CLI::DisplayError("No Cloud Storage Server profiles have been created.  Try creating your first Cloud Storage Server profile with the command:  profiles create");
			$cssprofile = CLI::GetLimitedUserInputWithArgs($args, "profile", "Cloud Storage Server profile", false, "Available Cloud Storage Server profiles:", $cssprofiles, true, $suppressoutput);
		}

		return $cssprofile;
	}

	if ($cmdgroup === "profiles")
	{
		// Cloud Storage Server profiles.
		if ($cmd === "list")  DisplayResult(CSSProfilesList());
		else if ($cmd === "create")
		{
			do
			{
				$name = CLI::GetUserInputWithArgs($args, "name", "Cloud Storage Server profile name", false, "", $suppressoutput);
				$name = Str::FilenameSafe($name);
				$filename = $rootpath . "/css-profiles/" . $name . ".json";
				$found = file_exists($filename);
				if ($found)  CLI::DisplayError("A Cloud Storage Server profile with that name already exists.  The file '" . $filename . "' already exists.", false, false);
			} while ($found);

			do
			{
				$data = array();
				$data["host"] = CLI::GetUserInputWithArgs($args, "host", "Cloud Storage Server host", false, "To include a Remoted API Server URL, prefix it to the target host URL.", $suppressoutput);
				$data["apikey"] = CLI::GetUserInputWithArgs($args, "apikey", "API key", false, "", $suppressoutput);
				$data["created"] = time();

				// Attempt to connect to the host and cache the SSL certificate information.
				$cafile = $rootpath . "/css-profiles/" . $name . ".ca.pem";
				$certfile = $rootpath . "/css-profiles/" . $name . ".cert.pem";
				@unlink($cafile);
				@unlink($certfile);

				$css = new CloudStorageServerFiles();
				$result = $css->InitSSLCache($data["host"], $cafile, $certfile);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving Cloud Storage Server SSL certificate information.  Try again.", $result, false);

			} while (!$result["success"]);

			file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			chmod($filename, 0600);

			$result = array(
				"success" => true,
				"profile" => array(
					"id" => $name,
					"host" => $data["host"],
					"apikey" => $data["apikey"],
					"created" => $data["created"]
				)
			);

			DisplayResult($result);
		}
		else
		{
			$name = GetCSSProfileName();
			$filename = $rootpath . "/css-profiles/" . $name . ".json";
			$cafile = $rootpath . "/css-profiles/" . $name . ".ca.pem";
			$certfile = $rootpath . "/css-profiles/" . $name . ".cert.pem";

			if ($cmd === "get-info")
			{
				$data = json_decode(file_get_contents($filename), true);

				$result = array(
					"success" => true,
					"profile" => array(
						"id" => $name,
						"host" => $data["host"],
						"apikey" => $data["apikey"],
						"created" => $data["created"]
					)
				);

				DisplayResult($result);
			}
			else if ($cmd === "reset-certs")
			{
				$data = json_decode(file_get_contents($filename), true);

				@unlink($cafile);
				@unlink($certfile);

				$css = new CloudStorageServerFiles();
				$result = $css->InitSSLCache($data["host"], $cafile, $certfile);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving Cloud Storage Server SSL certificate information.", $result);

				$result = array(
					"success" => true,
					"profile" => array(
						"id" => $name,
						"host" => $data["host"],
						"apikey" => $data["apikey"],
						"created" => $data["created"]
					)
				);

				DisplayResult($result);
			}
			else if ($cmd === "delete")
			{
				@unlink($filename);
				@unlink($cafile);
				@unlink($certfile);

				$result = array(
					"success" => true
				);

				DisplayResult($result);
			}
		}
	}
	else
	{
		// Load a profile.
		$name = GetCSSProfileName();
		$filename = $rootpath . "/css-profiles/" . $name . ".json";
		$cafile = $rootpath . "/css-profiles/" . $name . ".ca.pem";
		$certfile = $rootpath . "/css-profiles/" . $name . ".cert.pem";

		$data = json_decode(file_get_contents($filename), true);

		$css = new CloudStorageServerFiles();
		$css->SetAccessInfo($data["host"], $data["apikey"], (file_exists($cafile) ? $cafile : false), (file_exists($certfile) ? file_get_contents($certfile) : false));

		if ($cmdgroup === "files")
		{
			// Cloud Storage Server /files.
			if ($cmd === "list")
			{
				// List folders/files.
				$path = CLI::GetUserInputWithArgs($args, "path", "Path", false, "", $suppressoutput);
				$path = ltrim(str_replace("\\", "/", $path), "/");

				$result = $css->GetObjectByPath($path);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $path . "'.", $result);
				$id = $result["body"]["object"]["id"];

				$result = $css->GetFolderList($id);

				DisplayResult($result);
			}
			else if ($cmd === "create-folder")
			{
				// Create folder.
				$path = CLI::GetUserInputWithArgs($args, "path", "Path", false, "", $suppressoutput);
				$path = ltrim(str_replace("\\", "/", $path), "/");

				$result = $css->GetObjectByPath("/");
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/'.", $result);
				$id = $result["body"]["object"]["id"];

				$result = $css->CreateFolder($id, $path);
				if (!$result["success"])  CLI::DisplayError("An error occurred while creating the folder '/" . $path . "'.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "copy")
			{
				// Copy folder or file.
				$src = CLI::GetUserInputWithArgs($args, "src", "Source path", false, "", $suppressoutput);
				$src = ltrim(str_replace("\\", "/", $src), "/");

				$dest = CLI::GetUserInputWithArgs($args, "dest", "Destination path", false, "", $suppressoutput);
				$dest = ltrim(str_replace("\\", "/", $dest), "/");

				$result = $css->GetObjectByPath($src);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $src . "'.", $result);
				$srcid = $result["body"]["object"]["id"];

				$result = $css->GetObjectByPath($dest);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $dest . "'.", $result);
				$destid = $result["body"]["object"]["id"];

				$result = $css->CopyObject($srcid, $destid);
				if (!$result["success"])  CLI::DisplayError("An error occurred while copying '/" . $src . "' to '/" . $dest . "'.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "move")
			{
				// Move a folder or file.
				$src = CLI::GetUserInputWithArgs($args, "src", "Source path", false, "", $suppressoutput);
				$src = ltrim(str_replace("\\", "/", $src), "/");

				$dest = CLI::GetUserInputWithArgs($args, "dest", "Destination path", false, "", $suppressoutput);
				$dest = ltrim(str_replace("\\", "/", $dest), "/");

				$result = $css->GetObjectByPath($src);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $src . "'.", $result);
				$srcid = $result["body"]["object"]["id"];

				$result = $css->GetObjectByPath($dest);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $dest . "'.", $result);
				$destid = $result["body"]["object"]["id"];

				$result = $css->MoveObject($srcid, $destid);
				if (!$result["success"])  CLI::DisplayError("An error occurred while moving '/" . $src . "' to '/" . $dest . "'.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "rename")
			{
				// Rename a folder or file.
				$path = CLI::GetUserInputWithArgs($args, "path", "Path", false, "", $suppressoutput);
				$path = ltrim(str_replace("\\", "/", $path), "/");

				$name = CLI::GetUserInputWithArgs($args, "name", "New name", false, "", $suppressoutput);

				$result = $css->GetObjectByPath($path);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $path . "'.", $result);
				$id = $result["body"]["object"]["id"];

				$result = $css->RenameObject($id, $name);
				if (!$result["success"])  CLI::DisplayError("An error occurred while renaming '/" . $path . "' to '" . $name . "'.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "list-trash")
			{
				// List items in the trash.
				$result = $css->GetTrashList();
				if (!$result["success"])  CLI::DisplayError("An error occurred while getting the list of items in the trash.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "trash")
			{
				// Put a folder or file into the trash.
				$path = CLI::GetUserInputWithArgs($args, "path", "Path", false, "", $suppressoutput);
				$path = ltrim(str_replace("\\", "/", $path), "/");

				$result = $css->GetObjectByPath($path);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $path . "'.", $result);
				$id = $result["body"]["object"]["id"];

				$result = $css->TrashObject($id);
				if (!$result["success"])  CLI::DisplayError("An error occurred while moving '/" . $path . "' to the trash.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "restore")
			{
				// Restore a folder or file from the trash.
				if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "item"))  $itemid = CLI::GetUserInputWithArgs($args, "item", "Item", false, "", $suppressoutput);
				else
				{
					$result = $css->GetTrashList();
					if (!$result["success"])  CLI::DisplayError("An error occurred while getting the list of items in the trash.", $result);

					$items = array();
					$pidcache = array();
					foreach ($result["body"]["items"] as $info)
					{
						// Attempt to get the original path.
						$display = $info["name"];
						$pid = $info["pid"];
						while ($pid > 0)
						{
							if (!isset($pidcache[$pid]))  $pidcache[$pid] = $css->GetObjectByID($pid);
							$result2 = $pidcache[$pid];
							if (!$result2["success"])  break;

							$display = $result2["body"]["object"]["name"] . "/" . $display;
							$pid = $result2["body"]["object"]["pid"];
						}

						$items[$info["id"]] = $display . " (" . $info["type"] . ", " . Str::ConvertBytesToUserStr($info["size"]) . ")";
					}

					if (!count($items))  CLI::DisplayError("No items found in the trash.");
					$itemid = CLI::GetLimitedUserInputWithArgs($args, "item", "Item", false, "Available items in the trash:", $items, true, $suppressoutput);
				}

				$result = $css->RestoreObject($itemid);
				if (!$result["success"])  CLI::DisplayError("An error occurred while restoring item " . $itemid . " from the trash.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "delete")
			{
				// Delete a file or folder.
				$path = CLI::GetUserInputWithArgs($args, "path", "Path", false, "", $suppressoutput);
				$path = ltrim(str_replace("\\", "/", $path), "/");

				if (preg_match('/^\d+$/', $path))  $id = $path;
				else
				{
					$result = $css->GetObjectByPath($path);
					if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $path . "'.", $result);
					$id = $result["body"]["object"]["id"];
				}

				$result = $css->DeleteObject($id);
				if (!$result["success"])  CLI::DisplayError("An error occurred while deleting item " . $id . ".", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "get-limits")
			{
				// Get information about user limits for uploads/downloads.
				$result = $css->GetUserLimits();
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving user limit information.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "upload")
			{
				// Upload a folder or file.
				do
				{
					$src = CLI::GetUserInputWithArgs($args, "src", "Source path (local)", false, "", $suppressoutput);
					$src2 = realpath($src);
					if (!is_string($src2))  CLI::DisplayError("The specified path '" . $src . "' does not exist.  Try again.", false, false);
				} while (!is_string($src2));
				$src2 = str_replace("\\", "/", $src2);

				$dest = CLI::GetUserInputWithArgs($args, "dest", "Destination path (remote)", false, "", $suppressoutput);
				$dest = ltrim(str_replace("\\", "/", $dest), "/");

				// Files that are newer or have changed size will be uploaded.
				$diff = CLI::GetYesNoUserInputWithArgs($args, "diff", "Different files only", "Y", "", $suppressoutput);
				$diffkey = $src2 . " => " . $dest;
				$newts = time();
				$basets = ($diff ? (isset($data["uploadinfo"][$diffkey]) ? $data["uploadinfo"][$diffkey] : $newts) : 0);

				// Delete files off the server.
				if (is_dir($src2))  $delete = CLI::GetYesNoUserInputWithArgs($args, "delete", "Delete removed paths (remote)", "N", "", $suppressoutput);
				else  $delete = false;

				// Create/Retrieve the root folder.
				$result = $css->GetObjectByPath("/");
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/'.", $result);
				$id = $result["body"]["object"]["id"];

				$result = $css->CreateFolder($id, $dest);
				if (!$result["success"])  CLI::DisplayError("An error occurred while creating the folder '/" . $dest . "'.", $result);
				$folderid = $result["body"]["folder"]["id"];

				// Initialize the stack.
				$stack = array();
				if (is_dir($src2))  $stack[] = array("type" => "folder", "src" => $src2, "dest" => "/" . $dest, "destid" => $folderid);
				else
				{
					$filename2 = Str::ExtractFilename($src2);

					$result = $css->GetObjectByPath($dest . "/" . $filename2);
					if ($result["success"])
					{
						if ($result["body"]["object"]["type"] !== "file")  CLI::DisplayError("The destination '/" . $dest . "/" . $filename2 . "' already exists but is not a file.", $result);

						$stack[] = array("type" => "file", "src" => $src2, "dest" => "/" . $dest . "/" . $filename2, "destid" => $folderid, "size" => $result["body"]["object"]["size"], "fileid" => $result["body"]["object"]["id"]);
					}
					else
					{
						if ($result["errorcode"] !== "object_not_found")  CLI::DisplayError("An error occurred while getting information about '/" . $dest . "/" . $filename2 . "'.", $result);

						$stack[] = array("type" => "file", "src" => $src2, "dest" => "/" . $dest . "/" . $filename2, "destid" => $folderid, "size" => -1);
					}
				}

				// Start uploading.
				while (count($stack))
				{
					$curr = array_pop($stack);
					$type = $curr["type"];
					$src = $curr["src"];
					$dest = $curr["dest"];
					$folderid = $curr["destid"];

					if (is_file($src) && $type === "file")
					{
						if ((filemtime($src) >= $basets && filemtime($src) < $newts) || filesize($src) !== $curr["size"])
						{
							$filename2 = Str::ExtractFilename($src);

							$result = $css->UploadFile($folderid, $filename2, false, $src, (isset($curr["fileid"]) ? $curr["fileid"] : 0));
							if (!$result["success"])  CLI::DisplayError("An error occurred while uploading '" . $src . "'.", $result, false);
							else
							{
								$result["body"]["src"] = $src;
								$result["body"]["dest"] = $dest;

								echo json_encode($result["body"], JSON_UNESCAPED_SLASHES) . "\n";
							}
						}
					}
					else if (is_dir($src) && $type === "folder")
					{
						// Retrieve remote folder.
						$result = $css->GetFolderList($folderid);
						if (!$result["success"])
						{
							CLI::DisplayError("An error occurred while retrieving folder '" . $dest . "'.", $result, false);

							continue;
						}

						// Compare the local system.
						$dir = opendir($src);
						if ($dir)
						{
							while (($file = readdir($dir)) !== false)
							{
								if ($file !== "." && $file !== "..")
								{
									// Skip symbolic links.  This isn't a backup system.
									if (is_link($src . "/" . $file))  continue;

									if (is_dir($src . "/" . $file))
									{
										if (isset($result["files"][$file]))  continue;

										if (isset($result["folders"][$file]))
										{
											$folderid2 = $result["folders"][$file]["id"];

											unset($result["folders"][$file]);
										}
										else
										{
											$result2 = $css->CreateFolder($folderid, $file);
											if (!$result2["success"])  continue;
											$folderid2 = $result2["body"]["folder"]["id"];
										}

										$stack[] = array("type" => "folder", "src" => $src . "/" . $file, "dest" => $dest . "/" . $file, "destid" => $folderid2);
									}
									else if (is_file($src . "/" . $file))
									{
										if (isset($result["folders"][$file]))  continue;

										if (!isset($result["files"][$file]))  $stack[] = array("type" => "file", "src" => $src . "/" . $file, "dest" => $dest . "/" . $file, "destid" => $folderid, "size" => -1);
										else
										{
											$stack[] = array("type" => "file", "src" => $src . "/" . $file, "dest" => $dest . "/" . $file, "destid" => $folderid, "size" => $result["files"][$file]["size"], "fileid" => $result["files"][$file]["id"]);

											unset($result["files"][$file]);
										}
									}
								}
							}

							if ($delete)
							{
								// Remove folders.
								foreach ($result["folders"] as $name => $info)
								{
									$css->DeleteObject($info["id"]);
								}

								// Remove files.
								foreach ($result["files"] as $name => $info)
								{
									$css->DeleteObject($info["id"]);
								}
							}

							closedir($dir);
						}
					}
				}

				// Save timestamp information for later diff runs.
				if ($diff)
				{
					if (!isset($data["uploadinfo"]))  $data["uploadinfo"] = array();
					$data["uploadinfo"][$diffkey] = $newts;

					file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
					chmod($filename, 0600);
				}
			}
			else if ($cmd === "download")
			{
				// Download a folder or file.
				do
				{
					$src = CLI::GetUserInputWithArgs($args, "src", "Source path (remote)", false, "", $suppressoutput);
					$src = ltrim(str_replace("\\", "/", $src), "/");

					$result = $css->GetObjectByPath("/" . $src);
					if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $src . "'.", $result, false);
				} while (!$result["success"]);

				$object = $result["body"]["object"];

				$dest = CLI::GetUserInputWithArgs($args, "dest", "Destination path (local)", false, "", $suppressoutput);
				$dest = rtrim(str_replace("\\", "/", $dest), "/");

				// Files that are newer or have changed size will be uploaded.
				$diff = CLI::GetYesNoUserInputWithArgs($args, "diff", "Different files only", "Y", "", $suppressoutput);

				// Delete files off the local machine.
				if ($object["type"] === "folder")  $delete = CLI::GetYesNoUserInputWithArgs($args, "delete", "Delete removed paths (local)", "N", "", $suppressoutput);
				else  $delete = false;

				// Get the server's timestamp.
				$result = $css->GetRootFolderID();
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving server information.", $result, false);

				// Prepare the local system.
				if (!is_dir($dest) && !mkdir($dest))  CLI::DisplayError("Unable to create the directory '" . $dest . "'.");
				$dest = realpath($dest);
				$dest = rtrim(str_replace("\\", "/", $dest), "/");

				$diffkey = $src . " => " . $dest;
				$newts = $result["body"]["time"];
				$basets = ($diff ? (isset($data["downloadinfo"][$diffkey]) ? $data["downloadinfo"][$diffkey] : $newts) : 0);

				// Initialize the stack.
				$stack = array();
				if ($object["type"] === "folder")  $stack[] = array("type" => "folder", "src" => "/" . $src, "srcobj" => $object, "dest" => $dest);
				else if (is_dir($dest . "/" . $object["name"]))  CLI::DisplayError("The destination '" . $dest . "/" . $object["name"] . "' already exists but is not a file.");
				else  $stack[] = array("type" => "file", "src" => "/" . $src, "srcobj" => $object, "dest" => $dest . "/" . $object["name"]);

				function Download_DeleteDirectory($path)
				{
					$dir = opendir($path);
					if ($dir)
					{
						while (($file = readdir($dir)) !== false)
						{
							if ($file !== "." && $file !== "..")
							{
								if (is_file($path . "/" . $file) || is_link($path . "/" . $file))  unlink($path . "/" . $file);
								else if (is_dir($path . "/" . $file))  Download_DeleteDirectory($path . "/" . $file);
							}
						}

						closedir($dir);

						rmdir($path);
					}
				}

				// Start downloading.
				while (count($stack))
				{
					$curr = array_pop($stack);
					$type = $curr["type"];
					$src = $curr["src"];
					$srcobj = $curr["srcobj"];
					$dest = $curr["dest"];

					if ($type === "file")
					{
						if (($srcobj["created"] >= $basets && $srcobj["created"] < $newts) || !file_exists($dest) || filesize($dest) !== $srcobj["size"])
						{
							$result = $css->DownloadFile($dest, $srcobj["id"]);
							if (!$result["success"])  CLI::DisplayError("An error occurred while downloading '" . $src . "'.", $result, false);
							else
							{
								$result = array(
									"success" => true,
									"id" => $srcobj["id"],
									"src" => $src,
									"dest" => $dest
								);

								echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
							}
						}
					}
					else if ($type === "folder")
					{
						// Retrieve local folder.
						$folders = array();
						$files = array();
						$dir = opendir($dest);
						if ($dir)
						{
							while (($file = readdir($dir)) !== false)
							{
								if ($file !== "." && $file !== "..")
								{
									// Ignore symbolic links.  This isn't a restore system.
									if (is_link($dest . "/" . $file))  continue;

									if (is_dir($dest . "/" . $file))  $folders[$file] = true;
									else if (is_file($dest . "/" . $file))  $files[$file] = true;
								}
							}

							closedir($dir);
						}

						// Compare the remote system.
						$result = $css->GetFolderList($srcobj["id"]);
						if (!$result["success"])
						{
							CLI::DisplayError("An error occurred while retrieving folder '" . $src . ".", $result, false);

							continue;
						}

						foreach ($result["folders"] as $file => $object)
						{
							if (isset($files[$file]))  continue;

							if (!isset($folders[$file]))  mkdir($dest . "/" . $file);

							$stack[] = array("type" => "folder", "src" => $src . "/" . $file, "srcobj" => $object, "dest" => $dest . "/" . $file);

							unset($folders[$file]);
						}

						foreach ($result["files"] as $file => $object)
						{
							if (isset($folders[$file]))  continue;

							$stack[] = array("type" => "file", "src" => $src . "/" . $file, "srcobj" => $object, "dest" => $dest . "/" . $file);

							unset($files[$file]);
						}

						if ($delete)
						{
							// Remove folders.
							foreach ($folders as $file => $val)
							{
								Download_DeleteDirectory($dest . "/" . $file);
							}

							// Remove files.
							foreach ($files as $file => $val)
							{
								@unlink($dest . "/" . $file);
							}
						}
					}
				}

				// Save timestamp information for later diff runs.
				if ($diff)
				{
					if (!isset($data["downloadinfo"]))  $data["downloadinfo"] = array();
					$data["downloadinfo"][$diffkey] = $newts;

					file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
					chmod($filename, 0600);
				}
			}
			else if ($cmd === "list-guests")
			{
				// Get the list of guests with access to this account.
				$result = $css->GetGuestList();
				if (!$result["success"])  CLI::DisplayError("An error occurred while getting the list of guests.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "create-guest")
			{
				// Add a guest to the user account.
				$path = CLI::GetUserInputWithArgs($args, "path", "Path", false, "", $suppressoutput);
				$path = ltrim(str_replace("\\", "/", $path), "/");

				$read = CLI::GetYesNoUserInputWithArgs($args, "read", "Read access", "Y", "", $suppressoutput);
				$write = CLI::GetYesNoUserInputWithArgs($args, "write", "Write access", "N", "", $suppressoutput);
				$delete = CLI::GetYesNoUserInputWithArgs($args, "delete", "Delete access", "N", "", $suppressoutput);
				$expires = (int)CLI::GetUserInputWithArgs($args, "expires", "Access time in seconds", false, "", $suppressoutput);

				if ($expires < 5 * 60)  $expires = 5 * 60;

				$result = $css->GetObjectByPath($path);
				if (!$result["success"])  CLI::DisplayError("An error occurred while retrieving object for '/" . $path . "'.", $result);
				$id = $result["body"]["object"]["id"];

				$result = $css->CreateGuest($id, $read, $write, $delete, time() + $expires);
				if (!$result["success"])  CLI::DisplayError("An error occurred while creating the guest.", $result);

				DisplayResult($result["body"]);
			}
			else if ($cmd === "delete-guest")
			{
				// Delete a guest.
				if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "guest"))  $guestid = CLI::GetUserInputWithArgs($args, "guest", "Guest", false, "", $suppressoutput);
				else
				{
					$result = $css->GetGuestList();
					if (!$result["success"])  CLI::DisplayError("An error occurred while getting the list of items in the trash.", $result);

					$guests = array();
					$pidcache = array();
					foreach ($result["body"]["guests"] as $guestinfo)
					{
						// Attempt to get the original path.
						$display = array();
						$pid = $guestinfo["info"]["rootid"];
						while ($pid > 0)
						{
							if (!isset($pidcache[$pid]))  $pidcache[$pid] = $css->GetObjectByID($pid);
							$result2 = $pidcache[$pid];
							if (!$result2["success"])  break;

							$display[] = $result2["body"]["object"]["name"];
							$pid = $result2["body"]["object"]["pid"];
						}

						$access = array();
						if ($guestinfo["info"]["read"])  $access[] = "Read";
						if ($guestinfo["info"]["write"])  $access[] = "Write";
						if ($guestinfo["info"]["delete"])  $access[] = "Delete";

						$guests[$guestinfo["id"]] = implode("/", array_reverse($display)) . " (" . implode(", ", $access) . ")";
					}

					if (!count($guests))  CLI::DisplayError("No guests found.");
					$guestid = CLI::GetLimitedUserInputWithArgs($args, "guest", "Guest", false, "Available guests:", $guests, true, $suppressoutput);
				}

				$result = $css->DeleteGuest($guestid);
				if (!$result["success"])  CLI::DisplayError("An error occurred while deleting a guest " . $guestid . ".", $result);

				DisplayResult($result["body"]);
			}
		}
	}
?>