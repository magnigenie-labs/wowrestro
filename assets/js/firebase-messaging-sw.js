/* global firebase */
importScripts( 'https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js' );
importScripts( 'https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js' );

firebase.initializeApp( {
	apiKey: 'AIzaSyCPLF7XTg3VObNQIK_ZrsywMK7WA7XhxX4',
	authDomain: 'woo-restro.firebaseapp.com',
	projectId: 'woo-restro',
	storageBucket: 'woo-restro.firebasestorage.app',
	messagingSenderId: '174492569623',
	appId: '1:174492569623:web:41c153d582c8b3f2f9cc52'
} );
const messaging = firebase.messaging();

self.addEventListener( 'install', function () { self.skipWaiting(); } );
self.addEventListener( 'activate', function ( event ) { event.waitUntil( self.clients.claim() ); } );

messaging.onBackgroundMessage( function ( message ) {
	const data = message.data || {};
	return self.registration.showNotification( data.title || 'New order', {
		body: data.body || '',
		data: { link: data.link || '/wp-admin/admin.php?page=wowrestro-app&route=/live-orders' },
		tag: 'wowrestro-order-' + ( data.order_id || Date.now() ),
		renotify: true
	} );
} );

self.addEventListener( 'notificationclick', function ( event ) {
	event.notification.close();
	event.waitUntil( clients.openWindow( event.notification.data.link ) );
} );
