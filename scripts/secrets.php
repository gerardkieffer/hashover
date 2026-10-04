<?php

declare(strict_types=1);

	// Copyright (C) 2014-2019 Jacob Barkdull
	//
	//	I, Jacob Barkdull, hereby release this work into the public domain. 
	//	This applies worldwide. If this is not legally possible, I grant any 
	//	entity the right to use this work for any purpose, without any 
	//	conditions, unless such conditions are required by law.
	//
	//--------------------
	//
	// IMPORTANT:
	//
	//	To maintain proper functionality when downloading or otherwise 
	//	upgrading to a new version of HashOver, it is important that you 
	//	preserve this file, unless directed otherwise.
	//
	//	It is also important to choose UNIQUE values for the encryption key, 
	//	admin nickname, and admin password, or else you're at risk of 
	//	someone hijacking the comment system to delete comments, edit 
	//	existing comments to post spam, and/or impersonate you or your 
	//	visitors in order to push some sort of agenda/propaganda, to defame 
	//	you or your visitors, or to imply endorsement of some product(s), 
	//	service(s), and/or political ideology.


	//
	//	The encryption key protects stored e-mail addresses and login
	//	cookies, use a long random value, for example the output of:
	//
	//		php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'
	//
	//	Changing the key of an existing installation makes stored e-mail
	//	addresses unreadable and logs everyone out.
	//
	//	The admin password may be given either as plain text or as a hash
	//	generated with, for example:
	//
	//		php -r 'echo password_hash("your password", PASSWORD_DEFAULT), PHP_EOL;'


	$encryption_key		= '8CharKey';			// Unique random encryption key, at least 8 characters (32 recommended)
	$notification_email	= 'example@example.com';	// E-mail for notification of new comments
	$admin_nickname		= 'admin';			// Nickname with admin rights
	$admin_password		= 'passwd';			// Password (or password_hash() hash) to gain admin rights
