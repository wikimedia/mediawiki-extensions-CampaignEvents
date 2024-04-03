Purpose
======================

This directory contains wrappers around commonly-used MediaWiki entities like UserIdentity and PageIdentity.

These wrappers serve two purposes: first, they help with DI and error handling when some legacy MediaWiki classes are involved (like `Linker`).
Second, and most importantly: the WMF Campaigns team originally considered putting the backend code outside MediaWiki, and only have
the frontend as a MediaWiki extension. For several reasons, it was decided that the backend code would be created as a MediaWiki extension,
but leaving the door open for a move outside MediaWiki in the future. By using these wrappers as much as possible, the extension code
is less tightly coupled to MediaWiki, and it should (hopefully) be easier to turn it into a standalone application in the future, if need be.
