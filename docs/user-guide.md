# User guide

UniConnect works in any modern browser and can be installed on phones and computers like an app.
Sign in at your university's UniConnect address (for the demo: `https://localhost`).

Demo accounts (password `Password123!` for all):

| Role | E-mail |
|---|---|
| Admin | `admin@uniconnect.test` |
| Lecturers | `mziezo@uniconnect.test`, `jshikongo@uniconnect.test` |
| Students | `student1@uniconnect.test` … `student10@uniconnect.test` |

- [Everyone](#everyone)
- [Students](#students)
- [Lecturers](#lecturers)
- [Administrators](#administrators)

## Everyone

**Navigation.** On a computer the menu is on the left (collapse it with the ☰ button or Ctrl/Cmd+B).
On a phone use the tabs at the bottom: Courses, Chats, Alerts, Profile. The sun/moon button switches
light and dark mode.

**Status pill.** The pill at the top shows *Online*, *Syncing…* or *Offline – changes will sync*. Tap
it to sync now. While offline the yellow bar "Offline – showing saved data" tells you that you are
looking at what is stored on your device.

**Chats.**
- *Chats* lists all conversations with the last message, time and unread count; search by name.
  Green dot = online.
- Start a chat with **+**: *Private chat* with anyone who shares a course with you, or *New group*.
  From a course's *People* tab, the speech-bubble button opens a private chat.
- Type and press **Enter** to send, **Shift+Enter** for a new line. 📎 sends a file (max 20 MB),
  📷 opens the camera on phones.
- Ticks: 🕓 waiting to send (offline) → ✓ sent → ✓✓ delivered → **blue ✓✓ read**. In groups, the ⋮ menu
  on your message → *Message info* shows who received and read it, and when.
- ⋮ on a message: *Reply* (quotes it) or *Delete for everyone* (your own messages, within 1 hour).
- Tap the chat name for info: members, *Mute* (no push notifications, unread count stays),
  *Leave group*.
- Scroll up to load older messages.

**Notifications.** The bell shows new announcements, assignments, quizzes, released grades, deadline
reminders and when you are added to a group. They also pop up as toasts. Tap one to open it.

**Profile.** Change your name, photo and password, choose which notifications are pushed to this
device, enable push notifications, install the app, and *Log out all devices*.

**Install the app.** Profile → *Install app*. On iPhone/iPad: Share → *Add to Home Screen* (then enable
notifications inside the installed app). The installed app opens without network and syncs later.

**Working offline.** Everything you opened before is available offline: courses, materials list,
announcements, assignments, your chats. You can still write messages, submit assignments and (lecturers)
draft announcements — they are sent automatically, in order, when you are back online. Quizzes need a
connection.

## Students

1. **Courses** shows the courses you are enrolled in. A course has tabs: *Overview* (upcoming deadlines
   and news), *Materials*, *Assignments*, *Quizzes*, *Grades*, *Announcements*, *Chat*, *People*.
2. **Materials** are grouped by week. Tap a file to open it (PDFs open in the browser). Switch on
   **Offline** next to a file to keep a copy on your device; it then opens without network.
3. **Assignments:** open one to read the instructions and *Submit* text and/or a file (*Take photo* on a
   phone). You can resubmit until the deadline. After the deadline, submissions are only accepted if
   the lecturer allows late work, and they are marked late (the server's clock decides). A graded
   submission can no longer be changed.
4. **Quizzes:** *Start quiz* starts the timer (shown at the top). Answers are saved automatically every
   few seconds; when time runs out the quiz is submitted for you. Results appear when your lecturer
   releases them; correct answers are shown once you cannot take the quiz again.
5. **Grades** shows released grades and your weighted total. You get a notification when a grade is
   released.
6. **Dashboard** lists deadlines in the next two weeks, recent announcements and unread chats.

## Lecturers

1. **Materials** → *Upload notes*: title, week, file (PDF, Word, PowerPoint, Excel, text or images, max
   20 MB). Untick *Visible to students* to keep a draft. Use ⋮ to edit or delete.
2. **Announcements** → *New announcement*. Publishing notifies every enrolled user (in-app, toast and
   push). Written offline? It is posted when you reconnect.
3. **Assignments** → *New assignment*: due date, max score, late policy (accept late work with a %
   penalty), optional attachment.
   - Open an assignment to see every student with *On time*, *Late*, *Missing* or *Not yet*; filter with
     the buttons. Click a row to read the submission, enter a score and feedback, and *Save grade*.
4. **Quizzes** → *New quiz*: multiple choice (tap the circle to mark the right option), true/false and
   short answer (with an expected answer it is marked automatically, case-insensitive; without one you
   mark it by hand). Set opening/closing times, time limit, attempts and shuffling. Questions are locked
   once a student has started. On the quiz page, click an attempt to review answers and award points
   for short answers.
5. **Grades** (gradebook): one column per assignment/quiz (+ *Column* for manual items such as
   participation). Set each column's weight; the total is weighted over graded items. **Release** makes
   a column visible to students and notifies them. **CSV** downloads the gradebook.
6. **Course chat:** every course has a group chat with all enrolled people. Lecturers are its admins and
   can switch it to *Announcements only* (chat info), so only lecturers can post.
7. **Dashboard** lists submissions waiting for grading, quiz answers to mark and recent activity.

## Administrators

1. **Users:** search, filter by role, *New user*, *Import CSV* (columns `name,email,role,student_number,
   password`; missing passwords are generated and shown once). Click a user to change the role, set a
   password or deactivate them (they are signed out everywhere and cannot sign in).
2. **Courses & enrolments:** *New course* (with lecturers). Click a course to add or remove students and
   lecturers. Each course automatically gets a group chat whose members follow the enrolment.
3. **System status:** every running instance (API, Reverb, workers, scheduler) with its host, health,
   last heartbeat and uptime; what the gateway routes to; MySQL primary and replica with replication lag;
   Redis, MinIO, queues and live WebSocket connections. Refreshes every 5 seconds. New instances
   (`make scale API=3`) appear on their own; stopped ones disappear.
4. **Audit log:** sign-ins (also failed ones), role changes, deactivations, enrolment changes, grade
   changes and releases, submissions. Filter by action, user and date; click a row for details.
