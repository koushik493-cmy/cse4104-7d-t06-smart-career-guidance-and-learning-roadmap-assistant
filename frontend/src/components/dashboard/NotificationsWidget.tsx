import { useNotifications } from "../../hooks/useDashboardData";
import { useQueryClient } from "@tanstack/react-query";
import { Card, CardHeader, CardTitle, CardContent } from "../ui/Card";
import { Skeleton } from "../ui/Skeleton";
import { Bell, BellRing, CheckCheck } from "lucide-react";
import { format } from "date-fns";
import api from "../../services/api";

function formatAlertDate(dateStr?: string) {
  if (!dateStr) return "Just now";
  try {
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return "Recently";
    return format(d, "MMM d, h:mm a");
  } catch {
    return "Recently";
  }
}

export function NotificationsWidget() {
  const queryClient = useQueryClient();
  const { data: rawNotifications, isLoading } = useNotifications();

  const notifications = Array.isArray(rawNotifications) ? rawNotifications : [];
  const unreadCount = notifications.filter((n: any) => n.status === "unread").length;
  const recentNotifs = notifications.slice(0, 3);

  const markAsRead = async (id: number) => {
    try {
      await api.put(`/notifications/${id}/read`);
      queryClient.invalidateQueries({ queryKey: ["notifications"] });
    } catch (e) {
      console.error("Failed to mark notification read", e);
    }
  };

  const markAllAsRead = async () => {
    try {
      await api.put("/notifications/read-all");
      queryClient.invalidateQueries({ queryKey: ["notifications"] });
    } catch (e) {
      console.error("Failed to mark all notifications read", e);
    }
  };

  if (isLoading) {
    return <Skeleton className="h-full min-h-[300px] w-full rounded-2xl" />;
  }

  return (
    <Card className="h-full flex flex-col hover:border-primary/50 transition-colors overflow-hidden">
      <CardHeader className="pb-3 border-b border-border">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <CardTitle className="text-lg font-medium">Recent Alerts</CardTitle>
            {unreadCount > 0 && (
              <span className="px-1.5 py-0.5 text-xs font-semibold rounded-full bg-destructive/15 text-destructive">
                {unreadCount} new
              </span>
            )}
          </div>
          <div className="flex items-center gap-2">
            {unreadCount > 0 && (
              <button
                onClick={markAllAsRead}
                className="text-xs text-primary hover:text-primary/80 font-medium transition-colors flex items-center gap-1"
                title="Mark all as read"
              >
                <CheckCheck className="h-3 w-3" />
                Read all
              </button>
            )}
            <div className="p-2 bg-destructive/10 rounded-full relative">
              <Bell className="h-4 w-4 text-destructive" />
              {unreadCount > 0 && (
                <span className="absolute top-1 right-1 flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-destructive opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-destructive"></span>
                </span>
              )}
            </div>
          </div>
        </div>
      </CardHeader>
      <CardContent className="flex-1 py-3 overflow-y-auto min-h-0">
        {recentNotifs.length > 0 ? (
          <ul className="space-y-3">
            {recentNotifs.map((notif: any) => {
              const isUnread = notif.status === "unread";
              return (
                <li
                  key={notif.id}
                  onClick={() => isUnread && markAsRead(notif.id)}
                  className={`flex items-start gap-3 p-2.5 rounded-lg transition-colors cursor-pointer ${
                    isUnread
                      ? "bg-primary/5 hover:bg-primary/10 border-l-2 border-primary"
                      : "hover:bg-muted/50"
                  }`}
                  title={isUnread ? "Click to mark as read" : undefined}
                >
                  <div
                    className={`mt-0.5 p-1.5 rounded-full shrink-0 ${
                      isUnread
                        ? "bg-destructive/10 text-destructive"
                        : "bg-muted text-muted-foreground"
                    }`}
                  >
                    {isUnread ? <BellRing className="h-4 w-4" /> : <Bell className="h-4 w-4" />}
                  </div>
                  <div className="flex flex-col min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-1">
                      <span
                        className={`text-sm truncate ${
                          isUnread ? "font-semibold text-foreground" : "font-medium text-muted-foreground"
                        }`}
                      >
                        {notif.title || "Alert"}
                      </span>
                      {isUnread && (
                        <span className="h-1.5 w-1.5 rounded-full bg-primary shrink-0" />
                      )}
                    </div>
                    <span className="text-xs text-muted-foreground mt-0.5 line-clamp-2">
                      {notif.message}
                    </span>
                    <span className="text-[10px] text-muted-foreground/75 mt-1">
                      {formatAlertDate(notif.created_at)}
                    </span>
                  </div>
                </li>
              );
            })}
          </ul>
        ) : (
          <div className="flex flex-col items-center justify-center h-full text-center space-y-3 py-6">
            <div className="p-3.5 rounded-full bg-muted">
              <Bell className="h-5 w-5 text-muted-foreground/50" />
            </div>
            <div>
              <p className="text-sm font-medium text-foreground">You're all caught up!</p>
              <p className="text-xs text-muted-foreground mt-1">No new notifications right now.</p>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
