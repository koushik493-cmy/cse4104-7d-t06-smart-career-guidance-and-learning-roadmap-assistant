import React, { useState, useEffect, useRef } from 'react';
import { 
  Bell, 
  CheckCheck, 
  Check,
  BellRing, 
  Sparkles, 
  Milestone, 
  Target, 
  Bot, 
  Briefcase, 
  Clock, 
  Trash2, 
  ExternalLink,
  ChevronRight
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Link } from 'react-router-dom';
import { useNotifications } from '../hooks/useDashboardData';
import { useQueryClient } from '@tanstack/react-query';
import api from '../services/api';

interface Notification {
  id: number;
  title: string;
  message: string;
  status: 'unread' | 'read';
  created_at: string;
}

function getRelativeTime(dateStr?: string): string {
  if (!dateStr) return 'Just now';
  try {
    const now = new Date();
    const past = new Date(dateStr);
    const diffMs = now.getTime() - past.getTime();
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);

    if (diffMin < 1) return 'Just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHour < 24) return `${diffHour}h ago`;
    if (diffDay < 7) return `${diffDay}d ago`;
    return past.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  } catch {
    return 'Recently';
  }
}

function getNotificationCategory(title: string, message: string) {
  const text = (title + ' ' + message).toLowerCase();
  if (text.includes('roadmap') || text.includes('phase') || text.includes('milestone')) {
    return {
      icon: Milestone,
      color: 'text-indigo-500 bg-indigo-500/10 border-indigo-500/20',
      tag: 'Roadmap',
      link: '/roadmap',
    };
  }
  if (text.includes('interview') || text.includes('mock') || text.includes('question')) {
    return {
      icon: Bot,
      color: 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20',
      tag: 'Interview',
      link: '/interview',
    };
  }
  if (text.includes('skill') || text.includes('gap')) {
    return {
      icon: Target,
      color: 'text-amber-500 bg-amber-500/10 border-amber-500/20',
      tag: 'Skills',
      link: '/skill-gap',
    };
  }
  if (text.includes('career') || text.includes('role') || text.includes('engineer')) {
    return {
      icon: Briefcase,
      color: 'text-purple-500 bg-purple-500/10 border-purple-500/20',
      tag: 'Career',
      link: '/career-goals',
    };
  }
  return {
    icon: Sparkles,
    color: 'text-primary bg-primary/10 border-primary/20',
    tag: 'Alert',
    link: '/dashboard',
  };
}

export const NotificationPanel: React.FC = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [filter, setFilter] = useState<'all' | 'unread'>('all');
  const panelRef = useRef<HTMLDivElement>(null);
  const queryClient = useQueryClient();
  const { data: rawNotifications = [], isLoading } = useNotifications();

  const notifications: Notification[] = Array.isArray(rawNotifications) ? rawNotifications : [];
  const unreadCount = notifications.filter((n) => n.status === 'unread').length;

  const filteredNotifications = notifications.filter((n) => {
    if (filter === 'unread') return n.status === 'unread';
    return true;
  });

  // Close on Escape key
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen]);

  const markAsRead = async (id: number, e?: React.MouseEvent) => {
    e?.stopPropagation();
    try {
      await api.put(`/notifications/${id}/read`);
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    } catch (error) {
      console.error('Failed to mark notification as read', error);
    }
  };

  const markAllAsRead = async () => {
    try {
      await api.put('/notifications/read-all');
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    } catch (error) {
      console.error('Failed to mark all as read', error);
    }
  };

  const deleteNotification = async (id: number, e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      await api.delete(`/notifications/${id}`);
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    } catch (error) {
      console.error('Failed to delete notification', error);
    }
  };

  return (
    <div className="relative" ref={panelRef}>
      {/* Navbar Trigger Button */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        aria-label="Notifications"
        aria-expanded={isOpen}
        className={`relative p-2 rounded-full transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${
          isOpen
            ? 'bg-muted text-foreground ring-2 ring-primary/20'
            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
        }`}
      >
        <Bell className="h-5 w-5 transition-transform duration-200 active:scale-95" />
        {unreadCount > 0 && (
          <span className="absolute top-1 right-1 flex h-2.5 w-2.5">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-destructive opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-destructive"></span>
          </span>
        )}
      </button>

      {/* Floating Dropdown Panel */}
      <AnimatePresence>
        {isOpen && (
          <>
            {/* Backdrop click outside */}
            <div
              className="fixed inset-0 z-40"
              onClick={() => setIsOpen(false)}
              aria-hidden="true"
            />

            {/* Dropdown Container */}
            <motion.div
              initial={{ opacity: 0, y: 10, scale: 0.96 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: 8, scale: 0.96 }}
              transition={{ duration: 0.18, ease: 'easeOut' }}
              className="absolute right-0 top-full mt-2.5 w-88 sm:w-[420px] rounded-2xl border border-border/80 bg-popover/98 backdrop-blur-xl shadow-2xl z-50 overflow-hidden flex flex-col"
              style={{ maxHeight: 'calc(100vh - 85px)' }}
            >
              {/* Header */}
              <div className="p-4 border-b border-border/70 bg-muted/30">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <div className="p-1.5 rounded-lg bg-primary/10 text-primary">
                      <BellRing className="h-4 w-4" />
                    </div>
                    <div>
                      <h3 className="font-semibold text-sm text-foreground leading-none">Notifications</h3>
                      <p className="text-[11px] text-muted-foreground mt-0.5">
                        {unreadCount > 0 ? `${unreadCount} unread update${unreadCount > 1 ? 's' : ''}` : 'All caught up'}
                      </p>
                    </div>
                  </div>

                  {unreadCount > 0 && (
                    <button
                      onClick={markAllAsRead}
                      className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-primary hover:text-primary-foreground hover:bg-primary rounded-lg transition-all"
                    >
                      <CheckCheck className="h-3.5 w-3.5" />
                      Mark all read
                    </button>
                  )}
                </div>

                {/* Filter Tabs */}
                <div className="flex items-center gap-2 mt-3 pt-2 border-t border-border/50">
                  <button
                    onClick={() => setFilter('all')}
                    className={`px-3 py-1 rounded-full text-xs font-medium transition-all ${
                      filter === 'all'
                        ? 'bg-primary text-primary-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                    }`}
                  >
                    All ({notifications.length})
                  </button>
                  <button
                    onClick={() => setFilter('unread')}
                    className={`px-3 py-1 rounded-full text-xs font-medium transition-all ${
                      filter === 'unread'
                        ? 'bg-primary text-primary-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                    }`}
                  >
                    Unread ({unreadCount})
                  </button>
                </div>
              </div>

              {/* Notification Items List */}
              <div className="overflow-y-auto divide-y divide-border/50 max-h-[380px] min-h-[160px]">
                {isLoading ? (
                  <div className="py-12 px-6 flex flex-col items-center justify-center space-y-3 text-center">
                    <div className="h-6 w-6 rounded-full border-2 border-primary border-t-transparent animate-spin" />
                    <p className="text-xs text-muted-foreground">Syncing notifications...</p>
                  </div>
                ) : filteredNotifications.length === 0 ? (
                  <div className="py-12 px-6 flex flex-col items-center justify-center text-center space-y-3">
                    <div className="p-3.5 rounded-2xl bg-muted/80 text-muted-foreground/60 shadow-inner">
                      <Bell className="h-6 w-6" />
                    </div>
                    <div>
                      <p className="text-sm font-semibold text-foreground">
                        {filter === 'unread' ? 'No unread notifications' : "You're all caught up!"}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1 max-w-[240px]">
                        {filter === 'unread' 
                          ? 'All your alerts have been marked as read.' 
                          : 'You will receive updates when roadmap steps, skills, or career milestones progress.'}
                      </p>
                    </div>
                  </div>
                ) : (
                  filteredNotifications.map((notif) => {
                    const isUnread = notif.status === 'unread';
                    const category = getNotificationCategory(notif.title, notif.message);
                    const CategoryIcon = category.icon;

                    return (
                      <div
                        key={notif.id}
                        onClick={() => isUnread && markAsRead(notif.id)}
                        className={`group relative flex items-start gap-3.5 p-3.5 transition-all duration-150 cursor-pointer ${
                          isUnread
                            ? 'bg-primary/[0.04] hover:bg-primary/[0.08] dark:bg-primary/[0.06] dark:hover:bg-primary/[0.12]'
                            : 'hover:bg-muted/50'
                        }`}
                      >
                        {/* Status Icon Badge */}
                        <div className={`mt-0.5 p-2 rounded-xl shrink-0 border transition-transform duration-200 group-hover:scale-105 ${category.color}`}>
                          <CategoryIcon className="h-4 w-4" />
                        </div>

                        {/* Text Content */}
                        <div className="flex-1 min-w-0 pr-6">
                          <div className="flex items-center gap-2 mb-1">
                            <span className="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.2 rounded bg-muted text-muted-foreground">
                              {category.tag}
                            </span>
                            <span className="text-[11px] text-muted-foreground/80 flex items-center gap-1">
                              <Clock className="h-3 w-3" />
                              {getRelativeTime(notif.created_at)}
                            </span>
                            {isUnread && (
                              <span className="h-2 w-2 rounded-full bg-primary shrink-0 ring-4 ring-primary/20" />
                            )}
                          </div>

                          <h4 className={`text-xs leading-snug line-clamp-1 ${
                            isUnread ? 'font-semibold text-foreground' : 'font-medium text-foreground/85'
                          }`}>
                            {notif.title || 'System Notification'}
                          </h4>

                          <p className="text-xs text-muted-foreground mt-1 line-clamp-2 leading-relaxed">
                            {notif.message}
                          </p>

                          {/* Quick navigation link */}
                          <div className="mt-2 flex items-center gap-3">
                            <Link
                              to={category.link}
                              onClick={() => setIsOpen(false)}
                              className="inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline"
                            >
                              View details
                              <ChevronRight className="h-3 w-3" />
                            </Link>
                          </div>
                        </div>

                        {/* Hover Quick Actions */}
                        <div className="absolute right-3 top-3 flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                          {isUnread && (
                            <button
                              onClick={(e) => markAsRead(notif.id, e)}
                              className="p-1 rounded-md hover:bg-primary/20 text-muted-foreground hover:text-primary transition-colors"
                              title="Mark as read"
                            >
                              <Check className="h-3.5 w-3.5" />
                            </button>
                          )}
                          <button
                            onClick={(e) => deleteNotification(notif.id, e)}
                            className="p-1 rounded-md hover:bg-destructive/20 text-muted-foreground hover:text-destructive transition-colors"
                            title="Dismiss notification"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        </div>
                      </div>
                    );
                  })
                )}
              </div>

              {/* Footer */}
              <div className="px-4 py-2.5 bg-muted/40 border-t border-border/70 flex items-center justify-between text-xs">
                <span className="text-[11px] text-muted-foreground flex items-center gap-1.5">
                  <Sparkles className="h-3 w-3 text-primary" />
                  Real-time career updates
                </span>
                <Link
                  to="/roadmap"
                  onClick={() => setIsOpen(false)}
                  className="text-[11px] font-medium text-primary hover:text-primary/80 inline-flex items-center gap-1"
                >
                  Go to Roadmap
                  <ExternalLink className="h-3 w-3" />
                </Link>
              </div>
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
};

export default NotificationPanel;
