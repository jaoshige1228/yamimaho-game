import { createRouter, createWebHistory } from 'vue-router';
import HomeView from './views/HomeView.vue';
import BattleView from './views/BattleView.vue';
import AdminView from './views/AdminView.vue';
import HubView from './views/HubView.vue';
import DungeonView from './views/DungeonView.vue';
import StatusView from './views/StatusView.vue';

export default createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
      meta: { title: 'やみまほ', showBack: false },
    },
    {
      path: '/hub',
      name: 'hub',
      component: HubView,
      meta: { title: '待機', showBack: true },
    },
    {
      path: '/dungeon',
      name: 'dungeon',
      component: DungeonView,
      meta: { title: 'ダンジョン', showBack: true },
    },
    {
      path: '/status',
      name: 'status',
      component: StatusView,
      meta: { title: 'ステータス', showBack: true },
    },
    {
      path: '/battle/:id?',
      name: 'battle',
      component: BattleView,
      props: true,
      meta: { title: '戦闘', showBack: true },
    },
    {
      path: '/admins',
      name: 'admin',
      component: AdminView,
      meta: { title: '管理', showBack: true },
    },
  ],
});
