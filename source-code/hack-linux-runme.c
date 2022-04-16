#include <stdio.h>
#include <stdlib.h>
#include <sys/types.h>
#include <unistd.h>

int main(int argc, char ** const argv) {
	setuid(0);
	setgid(0);

	while (1) {
	    system("/usr/bin/php /root/DDOS/main.cli.php");
	    printf("runme: PHP script died\n");
	}
	return 0;
}


/*
gcc -o suid-launch-script suid-launch-script.c
chown root ./suid-launch-script
chgrp root ./suid-launch-script
chmod u=rwxs,g=rwxs,o=rx ./suid-launch-script
*/
