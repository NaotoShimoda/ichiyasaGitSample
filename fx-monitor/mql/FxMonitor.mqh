//+------------------------------------------------------------------+
//| FxMonitor.mqh  - ラッコサーバーの api.php へ送信 (MQL4/MQL5 共通) |
//| 事前設定: ツール→オプション→エキスパートアドバイザー               |
//|   「WebRequestを許可するURLリスト」にサーバーURLを追加              |
//| 注意: WebRequest は EA/スクリプトのみ可（インジケーター不可）        |
//+------------------------------------------------------------------+
#property strict

string g_fxmUrl     = "";
string g_fxmKey     = "";
string g_fxmInfo    = "";
int    g_fxmTimeout = 5000;

void FxmInit(const string url, const string apiKey, const string info = "", const int timeoutMs = 5000)
{
   g_fxmUrl     = url;
   g_fxmKey     = apiKey;
   g_fxmInfo    = (info == "") ? MQLInfoString(MQL_PROGRAM_NAME) : info;
   g_fxmTimeout = timeoutMs;
}

string FxmEscape(const string s)
{
   string out = "";
   int len = StringLen(s);
   for(int i = 0; i < len; i++)
   {
      ushort c = StringGetCharacter(s, i);
      if(c == '"')       out += "\\\"";
      else if(c == '\\') out += "\\\\";
      else if(c == '\n') out += "\\n";
      else if(c == '\r') out += "\\r";
      else if(c == '\t') out += "\\t";
      else if(c < 0x20)  out += " ";
      else               out += ShortToString(c);
   }
   return out;
}

string FxmTfName(ENUM_TIMEFRAMES tf)
{
   if(tf == PERIOD_CURRENT) tf = (ENUM_TIMEFRAMES)Period();
   return StringSubstr(EnumToString(tf), 7); // "PERIOD_H1" -> "H1"
}

string FxmPrice(const string symbol, const double price)
{
   return DoubleToString(price, (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS));
}

// 共通ヘッダー部（account, info, kind）
string FxmHead(const string kind)
{
   return "{\"kind\":\"" + kind + "\""
        + ",\"account\":\"" + IntegerToString(AccountInfoInteger(ACCOUNT_LOGIN)) + "\""
        + ",\"info\":\"" + FxmEscape(g_fxmInfo) + "\"";
}

bool FxmPost(const string json)
{
   if(g_fxmUrl == "" || MQLInfoInteger(MQL_TESTER)) return false; // テスターでは WebRequest 不可

   char   data[], result[];
   string resHeaders;
   int n = StringToCharArray(json, data, 0, WHOLE_ARRAY, CP_UTF8);
   if(n > 0) ArrayResize(data, n - 1); // 終端 NUL を除去

   string headers = "Content-Type: application/json\r\nX-Api-Key: " + g_fxmKey + "\r\n";
   ResetLastError();
   int code = WebRequest("POST", g_fxmUrl, headers, g_fxmTimeout, data, result, resHeaders);
   if(code == -1)
   {
      PrintFormat("FxMonitor: WebRequest失敗 err=%d（許可URLリストに %s を追加したか確認）", GetLastError(), g_fxmUrl);
      return false;
   }
   if(code != 200)
   {
      PrintFormat("FxMonitor: HTTP %d %s", code, CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8));
      return false;
   }
   return true;
}

bool FxmSendHeartbeat()
{
   return FxmPost(FxmHead("heartbeat") + "}");
}

// trend: 1=上昇 -1=下降 0=レンジ / dataJson: 任意の JSON オブジェクト（例 {"ma20":150.1}）
bool FxmSendSnapshot(const string symbol, const ENUM_TIMEFRAMES tf, const int trend,
                     const double price, const datetime barTime, const string dataJson = "")
{
   string json = FxmHead("snapshot")
      + ",\"symbol\":\"" + FxmEscape(symbol) + "\""
      + ",\"timeframe\":\"" + FxmTfName(tf) + "\""
      + ",\"trend\":" + IntegerToString(trend)
      + ",\"price\":" + FxmPrice(symbol, price)
      + ",\"bar_time\":\"" + TimeToString(barTime, TIME_DATE | TIME_MINUTES) + "\"";
   if(dataJson != "") json += ",\"data\":" + dataJson;
   return FxmPost(json + "}");
}

// 同一 (口座, 通貨ペア, 足, side, barTime) はサーバー側で重複排除される
bool FxmSendSignal(const string symbol, const ENUM_TIMEFRAMES tf, const string side,
                   const double price, const datetime barTime, const string message = "",
                   const bool notify = true)
{
   string json = FxmHead("signal")
      + ",\"symbol\":\"" + FxmEscape(symbol) + "\""
      + ",\"timeframe\":\"" + FxmTfName(tf) + "\""
      + ",\"side\":\"" + FxmEscape(side) + "\""
      + ",\"price\":" + FxmPrice(symbol, price)
      + ",\"bar_time\":\"" + TimeToString(barTime, TIME_DATE | TIME_MINUTES) + "\""
      + ",\"message\":\"" + FxmEscape(message) + "\""
      + ",\"notify\":" + (notify ? "true" : "false");
   return FxmPost(json + "}");
}
