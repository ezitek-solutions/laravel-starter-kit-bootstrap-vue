require('./bootstrap');

import { createApp } from 'vue';

createApp({
    data() {
        return {
            greeting: 'Hello World!'
        };
    }
}).mount('#app');