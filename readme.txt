# Firebase Messaging FCM HTTP V1

## Setup Instructions

### Firebase Setup

1. Create a Firebase project at [Firebase Console](https://console.firebase.google.com/).
2. Navigate to **Project Settings** and select the **Service Accounts** tab.
3. Click on **Generate New Private Key** to download the JSON file.
4. Copy the contents of the JSON file.

### Plugin Configuration

1. Go to the WordPress admin dashboard.
2. Navigate to **FCM Notifications » FCM Settings**.
3. Paste the JSON contents into the **Service Account JSON** field.
4. Enter your **Firebase Project ID**.
5. Save the settings.

### Integrate with React Native App

1. Install the `@react-native-firebase/messaging` package.
2. Configure Firebase in your React Native app.
3. Implement code to register the device token and send it to the WordPress REST API endpoint `/wp-json/fcm/v1/subscribe`.
4. Handle incoming messages and notifications in your app.
5. To unsubscribe, send a request to `/wp-json/fcm/v1/unsubscribe`.

### Sending Notifications

1. When creating or updating a post of the selected types, you will see a meta box titled **FCM Notification**.
2. Check the option **Send notification via FCM on save** if you wish to send a notification.
3. Optionally, schedule the notification by selecting a date and time.
4. Publish or update the post.

### Testing Notifications

1. Go to **FCM Notifications » Test Notification**.
2. Enter a title and message.
3. Click **Send Test Notification** to send a test message to all subscribers.
