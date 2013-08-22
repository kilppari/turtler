#############################################
#File:		Turtlerbot readme				#
#Author:	Pekka Mäkinen					#
#Source:	http://www.otit.fi/~kilppari/	#
#Bot v.		0.9.8							#
#Date:		06.02.2010						#
#############################################

In short: To run the bot, just type 'php run.php' on a command line.

This file goes through the basic functionality of the bot and gives examples of
how to add user made functions for the bot as commands or as services. It is
assumed that the reader has some knowledge of php programming.

[introduction]
Turtlerbot is a simple framework for an IRC bot. It enables easy implementation
of user made functions as the bots commands or services. Therefore it does not
implement any channel management tasks or similar commands by default. Personally
I coded this just to be able to use some of my php-functions on IRC, such as
parsing rss-feeds and returning the results on a channel. The bot is meant to be
run on a command line.

[basic functionality]
Basic commands are JOIN PART NICK and QUIT which must be commanded from another
IRC-client by private messaging the bot. These commands also require the bot's
password to be given to be effective. The default password is 'root'.

example :
'/msg botname root !join #channel'			//join a channel
'/msg botname root !join #channel password' //join a channel with password
'/msg botname root !part #channel			//leave channel
'/msg botname root !quit					//quit server and shut down the bot


All other commands must be addressed from a channel by calling the bot by it's
callsign and writing the command name and all the possible parameters divided by
commas ','. Bot has two callsigns: 'botname, ' and 'botname: '.

Two added commands are 'version' and 'commands'.

example:

'botname, version'	//tells the bot's version
'botname, commands' //lists all the added external commands (more on this below)

All other commands must be added before connecting the bot to a server.

[external functions]
To add a function for the bot as command, you must use the addFunction()-method
in the run.php.

The function:

	function test($int) { return $int + 5; }

And how you add it on run.php (assuming your bot-object's name in run.php is
$turtler)

	$turtler->addFunction("test");

Now you can call the bot from a channel by typing 'botname, test, 2' and the bot
responds with '7'. As such, command-functions must always return a value that can
be interpreted as a string.

On the other hand, service functions are functions that the bot runs all the time
and cannot be called for. These functions have access to variables that can be
saved outside the function call. Therefore these functions must always be made with
one default parameter, which is an array of variables that can be saved on bot's
'main memory'. This array has a default index of 'time' which holds the unix
timestamp of when the bot was started. Other indexes can vary from zero to n, depending
on what number was given when the service was added.

To make it more clear, here is an example.

	function service($vars) {
		if(($vars["time"] + (60*5) ) < time()) {
			$vars["time"] = time();
			$vars[0]++;
			return "five minutes passed " . $vars[0] . " time(s)";
		}
	}

Now you can add that function as a service by using the addService()-method. You
must define the functions name and how many variables it want to save. In this case
we only want one variable to be saved ($vars[0]) so we assign parameter value 1.

	$turtle->addService("service", 1);

Now the function keeps running and writes to the channel every five minutes the
following:

five minutes passed 1 time(s)
//and after five minutes:
five minutes passed 2 time(s)
and so on..

These service functions must also return only strings.


The source package should have two files; run.php and turtler.php. Turtler.php
includes the bot's core and can be modified freely. Just remember to give credits
to me. Run.php has the connection parameters and added functions and is
the file to be called to run the bot.