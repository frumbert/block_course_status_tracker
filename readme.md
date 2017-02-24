# Course Status Tracker

This is a Moodle 3.1 block that shows you a list of the courses you are enrolled in, with their completion status and links to the certificate (https://moodle.org/plugins/mod_customcert).

It also works as a configurable auto-enrol plugin for selectable courses, as well as a user management tool for members of the same cohort. It lets a user with a configurable role (/not/ a Moodle role - the users Department) easily flip between being a Teacher and Student in all courses they are enrolled into (this was a client requirement, seems wierd to me, but it's there).

## Integration
It expects to work with mpm2moodle (https://github.com/frumbert/auth_mpm2moodle), which is a custom version of wp2moodle (https://github.com/frumbert/wp2moodle-moodle) that logs people on from an external portal, and sets these fields on the user record:

- Institution - the company they work for
- Department - their position in that company

## What it does

It will:

    - Automatically create a group for each Institution on each course that a user is enrollable into
    - Automatically add and enable the manual enrolment plugin on each course that a user is enrollable into
    - Automatically configure the course to enable "Separate Groups" mode so users in each group can't see into other groups
    - Allow a user with a (configurable) specified Role Name (which is the user institution) to be a Manager.

Managers are students or teachers who can:

    - Choose which courses other users in the same Instution are able to be enrolled into (next time they log in - it's effectively auto-enrol)
    - Disable (suspend) users in the same cohort (it was imagined that a manager would want to hide people who had left their organisation).
    - Switch between Student and Teacher role on all courses they are enrolled into.

## What you could do with it

It's probably not that useful for you in its current form. You might like to:

    - Fork this repo and decouple it from mpm2moodle and rip out the Teacher switch.
    - Use the code as a basis for your own work.
    - Read my code, be temporarily horrified that it even works.

This originiated from a block of the same name by Azmat Ullah, Talha Noor, which is probably on the Moodle plugins site. Their code is mostly gone from this (I think), and I never got around to changing the name. Oops.

## Plugin Installation

1. Copy plugin folder in Moodle blocks folder
2. Login with Administrator on Moodle site
3. Install the plugin by clicking on 'update database' now button
4. Enable course completion settings
5. Log on as a user who has their Instituion and Department set

Login with admin account
Click on advanced feature under site administrator block
Checked 'Enable completion tracking' and click on 'Save'.
Goto course where you want to track the course status and enable course completion tracking
Click on 'Save changes'

## License

GPL3, same as Moodle. Go nuts.