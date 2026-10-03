//+------------------------------------------------------------------+
//| FxMonitorSample.mq5                                              |
//| 複数通貨ペア×複数時間足の環境認識をサーバーへ送信し、               |
//| MAクロスをシグナルとして Discord 通知する（発注はしない）           |
//+------------------------------------------------------------------+
#property strict
#property version "1.00"
#include "FxMonitor.mqh"

input string         InpUrl        = "https://your-domain/fxmon/api.php";
input string         InpApiKey     = "";
input string         InpSymbols    = "USDJPY,EURUSD,GBPJPY";
input string         InpTimeframes = "D1,H4,H1,M15";   // 環境認識する足
input string         InpSignalTFs  = "H1";             // MAクロスを通知する足
input int            InpFastMA     = 20;
input int            InpSlowMA     = 75;
input ENUM_MA_METHOD InpMAMethod   = MODE_EMA;
input int            InpSlopeBars  = 5;                // 長期MAの傾き判定本数
input int            InpHeartbeat  = 300;              // 死活監視の送信間隔(秒)

struct Watch
{
   string          sym;
   ENUM_TIMEFRAMES tf;
   int             hFast;
   int             hSlow;
   datetime        lastBar;
   bool            signal;
   int             trend;
};

Watch    g_w[];
datetime g_lastBeat = 0;

ENUM_TIMEFRAMES TfFromString(string s)
{
   StringToUpper(s);
   if(s == "M1")  return PERIOD_M1;
   if(s == "M5")  return PERIOD_M5;
   if(s == "M15") return PERIOD_M15;
   if(s == "M30") return PERIOD_M30;
   if(s == "H1")  return PERIOD_H1;
   if(s == "H4")  return PERIOD_H4;
   if(s == "D1")  return PERIOD_D1;
   if(s == "W1")  return PERIOD_W1;
   if(s == "MN1") return PERIOD_MN1;
   return PERIOD_CURRENT;
}

int SplitTrim(const string src, string &out[])
{
   int n = StringSplit(src, ',', out);
   for(int i = 0; i < n; i++)
   {
      StringTrimLeft(out[i]);
      StringTrimRight(out[i]);
   }
   return n;
}

bool InList(const string &list[], string v)
{
   StringToUpper(v);
   for(int i = 0; i < ArraySize(list); i++)
   {
      string x = list[i];
      StringToUpper(x);
      if(x == v) return true;
   }
   return false;
}

int OnInit()
{
   string syms[], tfs[], sigTfs[];
   int ns = SplitTrim(InpSymbols, syms);
   int nt = SplitTrim(InpTimeframes, tfs);
   SplitTrim(InpSignalTFs, sigTfs);

   ArrayResize(g_w, 0);
   for(int i = 0; i < ns; i++)
   {
      if(syms[i] == "") continue;
      if(!SymbolSelect(syms[i], true))
      {
         PrintFormat("通貨ペアが見つかりません: %s（サフィックスを確認）", syms[i]);
         return INIT_PARAMETERS_INCORRECT;
      }
      for(int j = 0; j < nt; j++)
      {
         ENUM_TIMEFRAMES tf = TfFromString(tfs[j]);
         if(tf == PERIOD_CURRENT)
         {
            PrintFormat("時間足が不正です: %s", tfs[j]);
            return INIT_PARAMETERS_INCORRECT;
         }
         Watch w;
         w.sym     = syms[i];
         w.tf      = tf;
         w.hFast   = iMA(w.sym, tf, InpFastMA, 0, InpMAMethod, PRICE_CLOSE);
         w.hSlow   = iMA(w.sym, tf, InpSlowMA, 0, InpMAMethod, PRICE_CLOSE);
         w.lastBar = 0;
         w.signal  = InList(sigTfs, tfs[j]);
         w.trend   = 0;
         if(w.hFast == INVALID_HANDLE || w.hSlow == INVALID_HANDLE)
         {
            PrintFormat("iMA作成失敗: %s %s", w.sym, tfs[j]);
            return INIT_FAILED;
         }
         int k = ArraySize(g_w);
         ArrayResize(g_w, k + 1);
         g_w[k] = w;
      }
   }

   FxmInit(InpUrl, InpApiKey);
   EventSetTimer(10);
   return INIT_SUCCEEDED;
}

void OnDeinit(const int reason)
{
   EventKillTimer();
   for(int i = 0; i < ArraySize(g_w); i++)
   {
      IndicatorRelease(g_w[i].hFast);
      IndicatorRelease(g_w[i].hSlow);
   }
}

void OnTick() {}

string TrendMark(const int t) { return t > 0 ? "▲" : (t < 0 ? "▼" : "◆"); }

// 同一通貨ペアの全時間足の環境（例 "D1▲ H4▲ H1▼"）
string Context(const string sym)
{
   string s = "";
   for(int i = 0; i < ArraySize(g_w); i++)
      if(g_w[i].sym == sym)
         s += FxmTfName(g_w[i].tf) + TrendMark(g_w[i].trend) + " ";
   return s;
}

void OnTimer()
{
   string sigSide[];
   int    sigIdx[];

   // 1) 新しい足が確定した組み合わせだけ環境認識を更新
   for(int i = 0; i < ArraySize(g_w); i++)
   {
      datetime t0 = iTime(g_w[i].sym, g_w[i].tf, 0);
      if(t0 == 0 || t0 == g_w[i].lastBar) continue;

      double f[], s[];
      ArraySetAsSeries(f, true);
      ArraySetAsSeries(s, true);
      if(CopyBuffer(g_w[i].hFast, 0, 1, 2, f) != 2) continue;                       // 未計算なら次回再試行
      if(CopyBuffer(g_w[i].hSlow, 0, 1, InpSlopeBars + 1, s) != InpSlopeBars + 1) continue;
      double close1 = iClose(g_w[i].sym, g_w[i].tf, 1);
      if(close1 == 0) continue;

      int trend = 0;
      if(close1 > s[0] && f[0] > s[0] && s[0] > s[InpSlopeBars]) trend = 1;
      else if(close1 < s[0] && f[0] < s[0] && s[0] < s[InpSlopeBars]) trend = -1;
      g_w[i].trend = trend;

      string data = "{\"fast\":" + FxmPrice(g_w[i].sym, f[0])
                  + ",\"slow\":" + FxmPrice(g_w[i].sym, s[0]) + "}";
      FxmSendSnapshot(g_w[i].sym, g_w[i].tf, trend, close1, iTime(g_w[i].sym, g_w[i].tf, 1), data);
      g_w[i].lastBar = t0;

      if(!g_w[i].signal) continue;
      string side = "";
      if(f[1] <= s[1] && f[0] > s[0]) side = "BUY";
      else if(f[1] >= s[1] && f[0] < s[0]) side = "SELL";
      if(side == "") continue;

      int k = ArraySize(sigIdx);
      ArrayResize(sigIdx, k + 1);
      ArrayResize(sigSide, k + 1);
      sigIdx[k]  = i;
      sigSide[k] = side;
   }

   // 2) 全時間足の環境が揃ってからシグナル送信（上位足の状況を添える）
   for(int k = 0; k < ArraySize(sigIdx); k++)
   {
      int i = sigIdx[k];
      string msg = StringFormat("MA%d/%d %s  環境: %s", InpFastMA, InpSlowMA,
                                sigSide[k] == "BUY" ? "GC" : "DC", Context(g_w[i].sym));
      if(!FxmSendSignal(g_w[i].sym, g_w[i].tf, sigSide[k], iClose(g_w[i].sym, g_w[i].tf, 1),
                        iTime(g_w[i].sym, g_w[i].tf, 1), msg))
         g_w[i].lastBar = 0; // 送信失敗時は次回再送（サーバー側で重複排除）
   }

   // 3) 死活監視
   if(TimeLocal() - g_lastBeat >= InpHeartbeat)
   {
      if(FxmSendHeartbeat()) g_lastBeat = TimeLocal();
   }
}
