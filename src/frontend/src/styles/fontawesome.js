import Vue from "vue";
import {library} from '@fortawesome/fontawesome-svg-core'
import {FontAwesomeIcon} from '@fortawesome/vue-fontawesome'
import {faSquare} from '@fortawesome/free-regular-svg-icons'
import {faCheckSquare, faDownload, faGear} from '@fortawesome/free-solid-svg-icons'

library.add(faSquare, faCheckSquare, faGear, faDownload);

Vue.component('fa', FontAwesomeIcon)
