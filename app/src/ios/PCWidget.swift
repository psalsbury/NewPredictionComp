// PredictionComp Home Screen widget (Pete, 2026-11-21): the next matchweek with picks missing and your place in your
// league. The app stores a per-player token in the shared App Group (WidgetBridgePlugin); the widget fetches
// https://predictioncomp.com/?api=widget&wt=TOKEN every 30 minutes, and whenever the app asks it to refresh.
import WidgetKit
import SwiftUI

let appGroup = "group.uk.salsbury.predictioncomp"

struct PCData: Decodable {
    struct Next: Decodable { let gameweek: Int; let first_kickoff: String; let first: String; let missing: Int; let total: Int; let next_missing: String? }
    struct League: Decodable { let name: String; let position: Int; let of: Int; let points: Int; let movement: Int }
    let signed_in: Bool
    let next: Next?
    let league: League?
}

struct PCEntry: TimelineEntry {
    let date: Date
    let data: PCData?
}

struct Provider: TimelineProvider {
    func placeholder(in context: Context) -> PCEntry { PCEntry(date: Date(), data: nil) }
    func getSnapshot(in context: Context, completion: @escaping (PCEntry) -> Void) {
        load { completion(PCEntry(date: Date(), data: $0)) }
    }
    func getTimeline(in context: Context, completion: @escaping (Timeline<PCEntry>) -> Void) {
        load { data in
            completion(Timeline(entries: [PCEntry(date: Date(), data: data)], policy: .after(Date().addingTimeInterval(30 * 60))))
        }
    }
    func load(_ done: @escaping (PCData?) -> Void) {
        let token = UserDefaults(suiteName: appGroup)?.string(forKey: "widgetToken") ?? ""
        var c = URLComponents(string: "https://predictioncomp.com/")!
        c.queryItems = [URLQueryItem(name: "api", value: "widget"), URLQueryItem(name: "wt", value: token)]
        var req = URLRequest(url: c.url!)
        req.timeoutInterval = 15
        req.cachePolicy = .reloadIgnoringLocalCacheData
        URLSession.shared.dataTask(with: req) { data, _, _ in
            done(data.flatMap { try? JSONDecoder().decode(PCData.self, from: $0) })
        }.resume()
    }
}

let mint = Color(red: 0.50, green: 0.88, blue: 0.69)
let ink = Color(red: 0.03, green: 0.08, blue: 0.13)
let soft = Color(red: 0.66, green: 0.76, blue: 0.83)

func kickoff(_ iso: String?) -> Date? {
    guard let iso = iso else { return nil }
    return ISO8601DateFormatter().date(from: iso)
}
func ordinal(_ n: Int) -> String {
    let f = NumberFormatter(); f.numberStyle = .ordinal
    return f.string(from: NSNumber(value: n)) ?? "\(n)"
}
func lockText(_ d: Date) -> String {
    let f = DateFormatter(); f.dateFormat = Calendar.current.isDateInToday(d) ? "'today' HH:mm" : "EEE HH:mm"
    return "Locks " + f.string(from: d)
}

struct PCWidgetView: View {
    @Environment(\.widgetFamily) var family
    let entry: PCEntry

    var body: some View {
        content
            .foregroundColor(.white)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            .widgetURL(URL(string: "https://predictioncomp.com/#predict"))
            .containerBackground(for: .widget) { ink }
    }

    @ViewBuilder var content: some View {
        if let d = entry.data {
            if family == .systemMedium {
                HStack(alignment: .top, spacing: 14) {
                    nextBlock(d).frame(maxWidth: .infinity, alignment: .leading)
                    leagueBlock(d).frame(maxWidth: .infinity, alignment: .leading)
                }
            } else {
                nextBlock(d)
            }
        } else {
            VStack(alignment: .leading, spacing: 4) {
                Text("PREDICTIONCOMP").font(.caption2.weight(.heavy)).foregroundColor(mint)
                Text("Open the app to load your picks").font(.footnote).foregroundColor(soft)
            }
        }
    }

    @ViewBuilder func nextBlock(_ d: PCData) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text("PREDICTIONCOMP").font(.caption2.weight(.heavy)).foregroundColor(mint)
            if let n = d.next {
                Text("Matchweek \(n.gameweek)").font(.headline)
                if !d.signed_in {
                    Text("Sign in to see your picks").font(.footnote).foregroundColor(soft)
                } else if n.missing == 0 {
                    Text("All picks in ✓").font(.subheadline.weight(.semibold)).foregroundColor(mint)
                } else {
                    Text("\(n.missing) of \(n.total) picks missing").font(.subheadline.weight(.semibold))
                }
                Spacer(minLength: 0)
                if let k = kickoff(n.next_missing ?? n.first_kickoff), d.signed_in && n.missing > 0 {
                    Text(lockText(k)).font(.caption).foregroundColor(soft)
                } else if let k = kickoff(n.first_kickoff) {
                    Text("Kick-off " + String(lockText(k).dropFirst(6))).font(.caption).foregroundColor(soft)
                }
            } else {
                Text("No matches in the next three weeks").font(.footnote).foregroundColor(soft)
            }
        }
    }

    @ViewBuilder func leagueBlock(_ d: PCData) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text("YOUR LEAGUE").font(.caption2.weight(.heavy)).foregroundColor(mint)
            if let l = d.league {
                Text("\(ordinal(l.position)) of \(l.of)").font(.title3.weight(.bold))
                Text(l.name).font(.footnote).lineLimit(1).foregroundColor(soft)
                Spacer(minLength: 0)
                Text("\(l.points) pts" + (l.movement > 0 ? "  ↑\(l.movement)" : l.movement < 0 ? "  ↓\(-l.movement)" : "")).font(.caption.weight(.semibold))
            } else {
                Text(d.signed_in ? "Join or start a league in the app" : "Sign in to see your league").font(.footnote).foregroundColor(soft)
            }
        }
    }
}

@main
struct PCWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "PCWidget", provider: Provider()) { entry in
            PCWidgetView(entry: entry)
        }
        .configurationDisplayName("PredictionComp")
        .description("Your next deadline, missing picks and league place.")
        .supportedFamilies([.systemSmall, .systemMedium])
    }
}
